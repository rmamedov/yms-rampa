<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Auth\AuthenticationService;
use App\Domain\Auth\Exception\AccountDisabledException;
use App\Domain\Auth\Exception\AccountLockedException;
use App\Domain\Auth\Exception\InvalidCredentialsException;
use App\Domain\Auth\Exception\RefreshReusedException;
use App\Domain\Auth\Exception\TwoFactorInvalidException;
use App\Domain\Auth\Exception\TwoFactorRequiredException;
use App\Domain\Identity\Role;
use App\Domain\Password\WeakPasswordException;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Флоу автентифікації staff-контуру: AUTH-10, AUTH-11, AUTH-12, AUTH-14,
 * AUTH-17, AUTH-31, AUTH-50, AUTH-53 та RBAC-26.
 */
#[CoversClass(AuthenticationService::class)]
final class AuthenticationServiceTest extends TestCase
{
    private const string PASSWORD = 'Rampa!Staff2026';

    private AuthContext $context;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
    }

    /**
     * AUTH-11: успішний логін повертає пару токенів і профіль
     * з роллю в однині та скоупом.
     */
    public function testSuccessfulLoginReturnsTokensAndProfile(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A', 'B'], self::PASSWORD);

        $result = $this->context->authentication->login('  Manager@Silpo.UA ', self::PASSWORD, 'PHPUnit', '10.0.0.1');

        self::assertSame($user->id(), $result->user->id());
        self::assertNotSame('', $result->tokens->accessToken);
        self::assertNotSame('', $result->tokens->refreshToken);

        $profile = $result->profile();
        self::assertSame('store_manager', $profile['role']);
        self::assertSame(['A', 'B'], $profile['scope']['storeIds']);
        self::assertFalse($profile['scope']['networkWide']);
        self::assertContains('booking.mark_unloaded', $profile['permissions']);
        self::assertNotContains('store.configure', $profile['permissions']);

        // AUTH-11: фіксується час останнього логіну
        self::assertEquals($this->context->clock->now(), $result->user->lastLoginAt());
    }

    public function testNetworkRoleProfileIsMarkedNetworkWide(): void
    {
        $this->context->createUser('boss@silpo.ua', Role::NetworkManager, [], self::PASSWORD);

        $profile = $this->context->authentication->login('boss@silpo.ua', self::PASSWORD)->profile();

        self::assertTrue($profile['scope']['networkWide']);
        self::assertSame([], $profile['scope']['storeIds']);
    }

    /**
     * AUTH-53: неіснуючий логін і невірний пароль дають ідентичну помилку.
     */
    public function testWrongPasswordAndUnknownLoginAreIndistinguishable(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $errors = [];

        foreach ([['manager@silpo.ua', 'WrongPass2026!'], ['nobody@silpo.ua', self::PASSWORD]] as [$login, $password]) {
            try {
                $this->context->authentication->login($login, $password);
                self::fail('Очікувалася відмова AUTH_INVALID_CREDENTIALS.');
            } catch (InvalidCredentialsException $exception) {
                $errors[] = [$exception->errorCode(), $exception->httpStatus(), $exception->userMessage()];
            }
        }

        self::assertSame($errors[0], $errors[1]);
        self::assertSame(['AUTH_INVALID_CREDENTIALS', 401, 'Невірний логін або пароль.'], $errors[0]);
    }

    /**
     * AUTH-12: деактивований акаунт не проходить навіть із вірним паролем.
     */
    public function testDisabledAccountCannotLogIn(): void
    {
        $this->context->createUser('fired@silpo.ua', Role::Analyst, [], self::PASSWORD, active: false);

        try {
            $this->context->authentication->login('fired@silpo.ua', self::PASSWORD);
            self::fail('Очікувалася відмова AUTH_ACCOUNT_DISABLED.');
        } catch (AccountDisabledException $exception) {
            self::assertSame('AUTH_ACCOUNT_DISABLED', $exception->errorCode());
            self::assertSame(403, $exception->httpStatus());
        }
    }

    /**
     * AUTH-50: 6-та спроба протягом 15 хв повертає 423 навіть із ВІРНИМ паролем.
     */
    public function testSixthAttemptIsLockedEvenWithCorrectPassword(): void
    {
        $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->context->authentication->login('manager@silpo.ua', 'Wrong!Pass2026');
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        try {
            $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);
            self::fail('Очікувалася відмова AUTH_ACCOUNT_LOCKED.');
        } catch (AccountLockedException $exception) {
            self::assertSame(423, $exception->httpStatus());
        }

        // Критерій 3.9.3: через 15 хвилин логін із коректним паролем успішний
        $this->context->clock->advance('+16 minutes');
        $result = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);
        self::assertNotSame('', $result->tokens->accessToken);
    }

    /**
     * Крайовий випадок 3.6: блокування не розкриває існування логіна.
     */
    public function testUnknownLoginIsAlsoThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->context->authentication->login('ghost@silpo.ua', 'Wrong!Pass2026');
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $this->expectException(AccountLockedException::class);
        $this->context->authentication->login('ghost@silpo.ua', 'Wrong!Pass2026');
    }

    /**
     * AUTH-60: пароль, збережений слабшими параметрами, перехешовується при логіні.
     */
    public function testPasswordIsRehashedOnLoginWhenParametersHardened(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);
        $legacyHash = password_hash(self::PASSWORD, \PASSWORD_BCRYPT);
        $user->rehashPassword($legacyHash, $this->context->clock->now());
        $this->context->users->save($user);

        $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);

        $stored = $this->context->users->findById($user->id());
        self::assertNotNull($stored);
        self::assertStringStartsWith('$argon2id$', $stored->passwordHash());
    }

    /**
     * AUTH-17: двокроковий логін із 2FA.
     */
    public function testTwoFactorLoginRequiresChallengeAndValidCode(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $user = $this->context->createUser('admin@silpo.ua', Role::SuperAdmin, [], self::PASSWORD);
        $user->enableTwoFactor($secret, $this->context->clock->now());
        $this->context->users->save($user);

        $challengeToken = null;

        try {
            $this->context->authentication->login('admin@silpo.ua', self::PASSWORD);
            self::fail('Очікувалася відмова AUTH_2FA_REQUIRED.');
        } catch (TwoFactorRequiredException $exception) {
            self::assertSame('AUTH_2FA_REQUIRED', $exception->errorCode());
            $challengeToken = $exception->challengeToken();
        }

        self::assertIsString($challengeToken);

        // Невірний код — AUTH_2FA_INVALID
        try {
            $this->context->authentication->completeTwoFactorLogin($challengeToken, '000000');
            self::fail('Очікувалася відмова AUTH_2FA_INVALID.');
        } catch (TwoFactorInvalidException $exception) {
            self::assertSame('AUTH_2FA_INVALID', $exception->errorCode());
        }

        // Challenge одноразовий (AUTH-62): повторне використання неможливе
        $code = $this->context->totp->codeAt($secret, $this->context->clock->now());

        try {
            $this->context->authentication->completeTwoFactorLogin($challengeToken, $code);
            self::fail('Challenge мав бути одноразовим.');
        } catch (TwoFactorInvalidException) {
            // очікувано
        }

        // Новий challenge + правильний код → успіх
        try {
            $this->context->authentication->login('admin@silpo.ua', self::PASSWORD);
        } catch (TwoFactorRequiredException $exception) {
            $challengeToken = $exception->challengeToken();
        }

        $result = $this->context->authentication->completeTwoFactorLogin(
            $challengeToken,
            $this->context->totp->codeAt($secret, $this->context->clock->now()),
        );

        self::assertSame($user->id(), $result->user->id());
    }

    /**
     * AUTH-31 + RBAC-26: refresh видає токен уже з НОВИМИ клеймами
     * після зміни ролі та скоупа.
     */
    public function testRefreshIssuesTokenWithUpdatedClaims(): void
    {
        $user = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A'], self::PASSWORD);
        $login = $this->context->authentication->login('operator@silpo.ua', self::PASSWORD);

        $user->changeRole(Role::StoreManager, $this->context->clock->now());
        $user->changeScope(['A', 'C'], $this->context->clock->now());
        $this->context->users->save($user);

        $this->context->clock->advance('+5 minutes');
        $refreshed = $this->context->authentication->refresh($login->tokens->refreshToken, 'PHPUnit', '10.0.0.1');

        $claims = $this->context->tokens->verifyAccessToken($refreshed->tokens->accessToken);
        self::assertSame(Role::StoreManager, $claims->role);
        self::assertSame(['A', 'C'], $claims->storeIds);
        self::assertSame($login->tokens->sessionId, $claims->sessionId);
    }

    /**
     * RBAC-26/AUTH-28: після деактивації refresh відхиляється і всі сесії гасяться.
     */
    public function testRefreshAfterDeactivationIsRejectedAndKillsSessions(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);
        $login = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);

        $user->deactivate($this->context->clock->now());
        $this->context->users->save($user);

        try {
            $this->context->authentication->refresh($login->tokens->refreshToken);
            self::fail('Очікувалася відмова AUTH_ACCOUNT_DISABLED.');
        } catch (AccountDisabledException $exception) {
            self::assertSame(403, $exception->httpStatus());
        }

        self::assertSame([], $this->context->refreshTokens->findActiveForUser($user->id(), $this->context->clock->now()));
    }

    /**
     * AUTH-14: зміна пароля вимагає поточного і гасить усі сесії, крім поточної.
     */
    public function testChangePasswordRevokesEveryOtherSession(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $current = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);
        $other = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);

        $this->context->authentication->changePassword(
            userId: $user->id(),
            currentPassword: self::PASSWORD,
            newPassword: 'Nova!Parolya2026',
            currentSessionId: $current->tokens->sessionId,
        );

        // Поточна сесія жива
        $this->context->authentication->refresh($current->tokens->refreshToken);

        // Інша сесія — погашена
        try {
            $this->context->authentication->refresh($other->tokens->refreshToken);
            self::fail('Сесія іншого пристрою мала бути відкликана.');
        } catch (RefreshReusedException) {
            // очікувано: токен погашено
        }

        // Новий пароль працює, старий — ні
        $this->context->authentication->login('manager@silpo.ua', 'Nova!Parolya2026');

        $this->expectException(InvalidCredentialsException::class);
        $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);
    }

    public function testChangePasswordRequiresCorrectCurrentPassword(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $this->expectException(InvalidCredentialsException::class);
        $this->context->authentication->changePassword($user->id(), 'Wrong!Pass2026', 'Nova!Parolya2026');
    }

    /**
     * AUTH-13: новий пароль перевіряється політикою, включно з історією.
     */
    public function testChangePasswordRejectsReusedPassword(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $this->context->authentication->changePassword($user->id(), self::PASSWORD, 'Nova!Parolya2026');

        try {
            $this->context->authentication->changePassword($user->id(), 'Nova!Parolya2026', self::PASSWORD);
            self::fail('Очікувалася відмова AUTH_WEAK_PASSWORD (повтор з історії).');
        } catch (WeakPasswordException $exception) {
            self::assertSame('AUTH_WEAK_PASSWORD', $exception->errorCode());
            self::assertStringContainsString('останні 5 паролів', implode(' ', $exception->violations()));
        }
    }

    /**
     * AUTH-32: logout гасить лише поточну сесію; allDevices — усі.
     */
    public function testLogoutSingleSessionAndAllDevices(): void
    {
        $user = $this->context->createUser('manager@silpo.ua', Role::StoreManager, ['A'], self::PASSWORD);

        $first = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);
        $second = $this->context->authentication->login('manager@silpo.ua', self::PASSWORD);

        $this->context->authentication->logout($first->tokens->refreshToken);
        self::assertCount(1, $this->context->refreshTokens->findActiveForUser($user->id(), $this->context->clock->now()));

        $this->context->authentication->logout($second->tokens->refreshToken, allDevices: true);
        self::assertSame([], $this->context->refreshTokens->findActiveForUser($user->id(), $this->context->clock->now()));
    }
}
