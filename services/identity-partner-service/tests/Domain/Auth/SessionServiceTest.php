<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Account\ClientType;
use App\Domain\Exception\AccountDisabledException;
use App\Domain\Exception\RefreshTokenReusedException;
use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Ротація та відкликання refresh-токенів: AUTH-30, AUTH-31, AUTH-32,
 * DRV-07, DRV-09 і критерій 3.9.4.
 */
final class SessionServiceTest extends TestCase
{
    private const string DRIVER_PASSWORD = 'Rmp7dK2xTq';

    private AuthTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
    }

    private function loginDriver(bool $rememberMe = true): \App\Domain\Auth\AuthResult
    {
        $this->env->givenDriver();

        return $this->env->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: '067 123 45 67',
            password: self::DRIVER_PASSWORD,
            rememberMe: $rememberMe,
        );
    }

    public function testRefreshIssuesNewPairWithinTheSameSession(): void
    {
        $login = $this->loginDriver();
        $this->env->clock->advance('+10 minutes');

        $refreshed = $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);

        self::assertSame($login->sid, $refreshed->sid, 'Ротація має лишатись у тому самому ланцюжку sid.');
        self::assertNotSame($login->refreshToken, $refreshed->refreshToken);
        self::assertNotSame($login->accessToken, $refreshed->accessToken);
        self::assertSame(900, $refreshed->accessExpiresIn);
    }

    public function testUsedRefreshTokenIsMarkedRedeemed(): void
    {
        $login = $this->loginDriver();
        $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);

        $old = $this->env->refreshTokens->findByHash(hash('sha256', $login->refreshToken));

        self::assertNotNull($old);
        self::assertTrue($old->isRedeemed());
    }

    public function testReplayOfRedeemedRefreshRevokesWholeChain(): void
    {
        // AUTH-31 + критерій 3.9.4: детекція крадіжки завершує всі сесії.
        $login = $this->loginDriver();
        $rotated = $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);

        try {
            $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);
            self::fail('Очікувався RefreshTokenReusedException.');
        } catch (RefreshTokenReusedException $exception) {
            self::assertSame('AUTH_REFRESH_REUSED', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
        }

        // Свіжий токен «чесного» пристрою теж більше не працює.
        $this->expectException(TokenInvalidException::class);
        $this->env->sessions->refresh(ClientType::DriverWeb, $rotated->refreshToken);
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $login = $this->loginDriver(rememberMe: false);
        $this->env->clock->advance('+31 days');

        $this->expectException(TokenExpiredException::class);
        $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);
    }

    public function testDriverSessionSurvivesEightyNineDaysWithRememberMe(): void
    {
        // DRV-07: водій не вводить пароль частіше ніж раз на 90 днів.
        $login = $this->loginDriver();
        $this->env->clock->advance('+89 days');

        $refreshed = $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);

        self::assertNotSame('', $refreshed->accessToken);
        // Вікно ковзне: після ротації знову 90 днів.
        self::assertSame(
            90 * 86400,
            $refreshed->refreshExpiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp(),
        );
    }

    public function testRotationPreservesShortSessionForDriverWithoutRememberMe(): void
    {
        $login = $this->loginDriver(rememberMe: false);
        $this->env->clock->advance('+1 day');

        $refreshed = $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);

        self::assertSame(
            30 * 86400,
            $refreshed->refreshExpiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp(),
        );
    }

    public function testUnknownRefreshTokenIsRejected(): void
    {
        $this->loginDriver();

        $this->expectException(TokenInvalidException::class);
        $this->env->sessions->refresh(ClientType::DriverWeb, str_repeat('a', 64));
    }

    public function testLogoutRevokesCurrentSession(): void
    {
        // DRV-09 / AUTH-32.
        $login = $this->loginDriver();
        $this->env->sessions->logout($login->refreshToken);

        $this->expectException(TokenInvalidException::class);
        $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);
    }

    public function testLogoutIsIdempotentForUnknownToken(): void
    {
        $this->env->sessions->logout(str_repeat('b', 64));

        self::assertSame([], $this->env->refreshTokens->all());
    }

    public function testLogoutDoesNotAffectOtherDevices(): void
    {
        $this->env->givenDriver();

        $phone = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
        $tablet = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        $this->env->sessions->logout($phone->refreshToken);
        $refreshed = $this->env->sessions->refresh(ClientType::DriverWeb, $tablet->refreshToken);

        self::assertSame($tablet->sid, $refreshed->sid);
    }

    public function testLogoutAllRevokesEverySession(): void
    {
        $driver = $this->env->givenDriver();

        $first = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
        $second = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        $this->env->sessions->logoutAll($driver->id);

        self::assertSame([], $this->env->refreshTokens->findActiveForAccount($driver->id, $this->env->clock->now()));

        $failures = 0;

        foreach ([$first, $second] as $session) {
            try {
                $this->env->sessions->refresh(ClientType::DriverWeb, $session->refreshToken);
            } catch (TokenInvalidException) {
                ++$failures;
            }
        }

        self::assertSame(2, $failures);
    }

    public function testRefreshFailsForDeactivatedAccountAndKillsSessions(): void
    {
        // AUTH-28: після деактивації постачальника сесії його водіїв гинуть.
        $driver = $this->env->givenDriver();
        $login = $this->loginDriverForExistingAccount();

        $this->env->provisioner->suspendSupplier($driver->supplierId);

        try {
            $this->env->sessions->refresh(ClientType::DriverWeb, $login->refreshToken);
            self::fail('Очікувався AccountDisabledException.');
        } catch (AccountDisabledException $exception) {
            self::assertSame('AUTH_ACCOUNT_DISABLED', $exception->errorCode());
        }

        self::assertSame([], $this->env->refreshTokens->findActiveForAccount($driver->id, $this->env->clock->now()));
    }

    public function testRefreshIssuedForDriverIsNotAcceptedBySupplierEndpoint(): void
    {
        // Контурна гігієна: refresh водія не можна пред'явити на supplier-web.
        $login = $this->loginDriver();

        $this->expectException(TokenInvalidException::class);
        $this->env->sessions->refresh(ClientType::SupplierWeb, $login->refreshToken);
    }

    private function loginDriverForExistingAccount(): \App\Domain\Auth\AuthResult
    {
        return $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
    }
}
