<?php

declare(strict_types=1);

namespace App\Tests\Domain\Auth;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\AccountDisabledException;
use App\Domain\Exception\AccountLockedException;
use App\Domain\Exception\InvalidCredentialsException;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Логін партнерського контуру: AUTH-12, AUTH-21, AUTH-23, AUTH-27, AUTH-50,
 * DRV-06, DRV-07, DRV-10.
 */
final class AuthenticationServiceTest extends TestCase
{
    private const string DRIVER_PASSWORD = 'Rmp7dK2xTq';

    private AuthTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
    }

    #[DataProvider('provideDriverLoginInputs')]
    public function testDriverLogsInWithAnyPhoneInputFormat(string $typedPhone): void
    {
        // AUTH-23 / DRV-06: акаунт створено з телефоном «067 123 45 67»,
        // а водій може ввести будь-який зі звичних форматів.
        $driver = $this->env->givenDriver(phone: '067 123 45 67', password: self::DRIVER_PASSWORD);

        $result = $this->env->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: $typedPhone,
            password: self::DRIVER_PASSWORD,
        );

        self::assertSame($driver->id, $result->profile->accountId);
        self::assertSame('+380671234567', $result->profile->login);
        self::assertSame(PartnerRole::Driver, $result->profile->role);
        self::assertNotSame('', $result->accessToken);
        self::assertNotSame('', $result->refreshToken);
    }

    /** @return iterable<string, array{string}> */
    public static function provideDriverLoginInputs(): iterable
    {
        yield 'канонічний' => ['+380671234567'];
        yield 'національний' => ['0671234567'];
        yield 'з пробілами' => ['067 123 45 67'];
        yield 'з дужками й дефісами' => ['(067) 123-45-67'];
        yield 'міжнародний з пробілами' => ['+38 067 123 45 67'];
        yield 'старий 8-0XX' => ['80671234567'];
        yield 'з кодом країни без плюса' => ['380671234567'];
        yield 'з крайовими пробілами' => ['  0671234567 '];
    }

    public function testAccessTokenOfDriverCarriesRoleSupplierAndDriverScope(): void
    {
        // AUTH-26: токен водія містить role=driver, scope.supplierId і driverId.
        $this->env->givenDriver(supplierId: 'sp-07', driverProfileId: 'du-12');

        $result = $this->env->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: '067 123 45 67',
            password: self::DRIVER_PASSWORD,
        );

        $claims = $this->env->codec->verifyAccessToken($result->accessToken);

        self::assertSame(PartnerRole::Driver, $claims->role);
        self::assertSame('sp-07', $claims->supplierId);
        self::assertSame('du-12', $claims->driverId);
        self::assertSame($result->sid, $claims->sid);
    }

    public function testSupplierLogsInWithEmailInAnyCase(): void
    {
        // AUTH-21: email нормалізується до нижнього регістру.
        $supplier = $this->env->givenSupplier(email: 'Sales@Postachalnyk.UA', password: 'Postach2026');

        $result = $this->env->authentication->login(
            client: ClientType::SupplierWeb,
            rawLogin: '  SALES@postachalnyk.ua ',
            password: 'Postach2026',
        );

        self::assertSame($supplier->id, $result->profile->accountId);
        self::assertSame('sales@postachalnyk.ua', $result->profile->login);
    }

    public function testWrongPasswordIsRejectedWithGenericMessage(): void
    {
        $this->env->givenDriver();

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'НеТойПароль1');
            self::fail('Очікувався InvalidCredentialsException.');
        } catch (InvalidCredentialsException $exception) {
            self::assertSame('AUTH_INVALID_CREDENTIALS', $exception->errorCode());
            self::assertSame(401, $exception->httpStatus());
            self::assertSame('Невірний логін або пароль.', $exception->getMessage());
        }
    }

    public function testUnknownLoginIsIndistinguishableFromWrongPassword(): void
    {
        // AUTH-53 + крайовий випадок 3.6: тексти й коди мають збігатись.
        $this->env->givenDriver();

        $unknown = null;
        $wrongPassword = null;

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0509999999', 'ЩосьТам123');
        } catch (InvalidCredentialsException $exception) {
            $unknown = $exception;
        }

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'ЩосьТам123');
        } catch (InvalidCredentialsException $exception) {
            $wrongPassword = $exception;
        }

        self::assertInstanceOf(InvalidCredentialsException::class, $unknown);
        self::assertInstanceOf(InvalidCredentialsException::class, $wrongPassword);
        self::assertSame($unknown->getMessage(), $wrongPassword->getMessage());
        self::assertSame($unknown->errorCode(), $wrongPassword->errorCode());
    }

    public function testDeactivatedAccountCannotLogInEvenWithCorrectPassword(): void
    {
        // AUTH-12: помилка AUTH_ACCOUNT_DISABLED, 403.
        $this->env->givenDriver(active: false);

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
            self::fail('Очікувався AccountDisabledException.');
        } catch (AccountDisabledException $exception) {
            self::assertSame('AUTH_ACCOUNT_DISABLED', $exception->errorCode());
            self::assertSame(403, $exception->httpStatus());
            self::assertSame('Обліковий запис деактивовано. Зверніться до адміністратора.', $exception->getMessage());
        }
    }

    public function testSupplierCannotLogInThroughDriverWeb(): void
    {
        // DRV-10: driver-web призначений лише для ролі driver.
        $this->env->givenSupplier(email: 'sales@postachalnyk.ua', password: 'Postach2026');

        $this->expectException(InvalidCredentialsException::class);
        $this->env->authentication->login(ClientType::DriverWeb, 'sales@postachalnyk.ua', 'Postach2026');
    }

    public function testDriverCannotLogInThroughSupplierWeb(): void
    {
        $this->env->givenDriver();

        $this->expectException(InvalidCredentialsException::class);
        $this->env->authentication->login(ClientType::SupplierWeb, '+380671234567', self::DRIVER_PASSWORD);
    }

    public function testFiveFailedAttemptsLockAccountForFifteenMinutes(): void
    {
        // AUTH-50 / DRV-11 / критерій 3.9.3.
        $this->env->givenDriver();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Невірний'.$attempt);
                self::fail('Очікувався InvalidCredentialsException.');
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        try {
            // 6-та спроба — навіть із ПРАВИЛЬНИМ паролем.
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
            self::fail('Очікувався AccountLockedException.');
        } catch (AccountLockedException $exception) {
            self::assertSame('AUTH_ACCOUNT_LOCKED', $exception->errorCode());
            self::assertSame(423, $exception->httpStatus());
            self::assertGreaterThan(0, $exception->retryAfterSeconds);
            self::assertLessThanOrEqual(900, $exception->retryAfterSeconds);
        }
    }

    public function testLockExpiresAfterFifteenMinutes(): void
    {
        $this->env->givenDriver();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Невірний'.$attempt);
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $this->env->clock->advance('+16 minutes');

        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        self::assertNotSame('', $result->accessToken);
    }

    public function testSuccessfulLoginResetsFailureCounter(): void
    {
        $this->env->givenDriver();

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Невірний'.$attempt);
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        // Після успіху лічильник обнулено: ще 4 невдалі спроби не блокують.
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Невірний'.$attempt);
                self::fail('Очікувався InvalidCredentialsException.');
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        self::assertNotSame('', $result->accessToken);
    }

    public function testThrottlingIsNotBypassedByDifferentPhoneFormatting(): void
    {
        // Усі формати зводяться до одного логіна, тож і лічильник спільний.
        $this->env->givenDriver();
        $formats = ['0671234567', '067 123 45 67', '(067)123-45-67', '+380671234567', '80671234567'];

        foreach ($formats as $format) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, $format, 'ХибнийПароль1');
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $this->expectException(AccountLockedException::class);
        $this->env->authentication->login(ClientType::DriverWeb, '+38 067 123 45 67', self::DRIVER_PASSWORD);
    }

    public function testUnknownLoginIsAlsoThrottled(): void
    {
        // Крайовий випадок 3.6: блокування не розкриває існування логіна.
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            try {
                $this->env->authentication->login(ClientType::DriverWeb, '0509999999', 'Пароль'.$attempt);
            } catch (InvalidCredentialsException) {
                // очікувано
            }
        }

        $this->expectException(AccountLockedException::class);
        $this->env->authentication->login(ClientType::DriverWeb, '0509999999', 'Пароль6');
    }

    public function testDriverRefreshTokenLivesNinetyDaysWithRememberMe(): void
    {
        // AUTH-27 / DRV-07: довга сесія водія.
        $this->env->givenDriver();

        $result = $this->env->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: '0671234567',
            password: self::DRIVER_PASSWORD,
            rememberMe: true,
        );

        self::assertSame(90 * 86400, $result->refreshExpiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp());
    }

    public function testDriverRefreshTokenLivesThirtyDaysWithoutRememberMe(): void
    {
        $this->env->givenDriver();

        $result = $this->env->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: '0671234567',
            password: self::DRIVER_PASSWORD,
            rememberMe: false,
        );

        self::assertSame(30 * 86400, $result->refreshExpiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp());
    }

    public function testSupplierRefreshTokenAlwaysLivesThirtyDays(): void
    {
        // Розділ 3.4: у постачальника довгої сесії немає навіть із rememberMe.
        $this->env->givenSupplier(password: 'Postach2026');

        $result = $this->env->authentication->login(
            client: ClientType::SupplierWeb,
            rawLogin: 'sales@postachalnyk.ua',
            password: 'Postach2026',
            rememberMe: true,
        );

        self::assertSame(30 * 86400, $result->refreshExpiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp());
    }

    public function testAccessTokenAlwaysLivesFifteenMinutesForBothClients(): void
    {
        $this->env->givenDriver();
        $this->env->givenSupplier(password: 'Postach2026');

        $driverResult = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
        $supplierResult = $this->env->authentication->login(ClientType::SupplierWeb, 'sales@postachalnyk.ua', 'Postach2026');

        self::assertSame(900, $driverResult->accessExpiresIn);
        self::assertSame(900, $supplierResult->accessExpiresIn);
    }

    public function testRefreshTokenIsStoredOnlyAsSha256Hash(): void
    {
        // AUTH-30 / DATA-19.
        $this->env->givenDriver();
        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);

        $stored = $this->env->refreshTokens->all();

        self::assertCount(1, $stored);
        self::assertNotSame($result->refreshToken, $stored[0]->tokenHash);
        self::assertSame(hash('sha256', $result->refreshToken), $stored[0]->tokenHash);
        self::assertSame(64, \strlen($stored[0]->tokenHash));
    }

    public function testFailedAttemptsAreAuditedWithReason(): void
    {
        // AUTH-52: кожна спроба потрапляє в аудит-журнал.
        $this->env->givenDriver();

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Хибний123', ip: '10.0.0.7', userAgent: 'driver-web/1.0');
        } catch (InvalidCredentialsException) {
            // очікувано
        }

        $attempts = $this->env->loginAttempts->all();

        self::assertCount(1, $attempts);
        self::assertFalse($attempts[0]->success);
        self::assertSame('bad_password', $attempts[0]->reason);
        self::assertSame('10.0.0.7', $attempts[0]->ip);
        self::assertSame('driver-web/1.0', $attempts[0]->userAgent);
    }

    public function testLastLoginTimestampIsRecorded(): void
    {
        $driver = $this->env->givenDriver();

        self::assertNull($driver->lastLoginAt());

        $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
        $reloaded = $this->env->accounts->findById($driver->id);

        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->lastLoginAt());
        self::assertSame($this->env->clock->now()->getTimestamp(), $reloaded->lastLoginAt()->getTimestamp());
    }

    public function testPasswordHashIsNeverExposedInProfile(): void
    {
        // AUTH-61 / DATA-35.
        $driver = $this->env->givenDriver();
        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', self::DRIVER_PASSWORD);
        $payload = $result->toArray();

        self::assertArrayNotHasKey('passwordHash', $payload['profile']);
        self::assertStringNotContainsString($driver->passwordHash(), (string) json_encode($payload));
        self::assertStringNotContainsString(self::DRIVER_PASSWORD, (string) json_encode($payload));
    }
}
