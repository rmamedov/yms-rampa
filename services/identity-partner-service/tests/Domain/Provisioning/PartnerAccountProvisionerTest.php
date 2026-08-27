<?php

declare(strict_types=1);

namespace App\Tests\Domain\Provisioning;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerRole;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Exception\InvalidCredentialsException;
use App\Domain\Exception\InvalidLoginFormatException;
use App\Domain\Exception\LoginAlreadyTakenException;
use App\Domain\Exception\WeakPasswordException;
use App\Domain\Provisioning\CreatePartnerAccount;
use App\Domain\Security\DriverPasswordGenerator;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Створення акаунтів партнерського контуру: AUTH-20, AUTH-23, AUTH-24,
 * AUTH-25, AUTH-28, AUTH-29, DATA-35 і критерії приймання 3.3.2.
 */
final class PartnerAccountProvisionerTest extends TestCase
{
    private AuthTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
    }

    public function testDriverCreatedWithNationalPhoneIsStoredInE164(): void
    {
        // Критерій приймання 3.3.2: «067 123 45 67» → «+380671234567».
        $credentials = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '067 123 45 67',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
            driverProfileId: 'du-99',
        ));

        self::assertSame('+380671234567', $credentials->profile->login);
        self::assertNotNull($this->env->accounts->findByLogin('+380671234567'));
    }

    public function testDriverPasswordIsGeneratedAndReturnedExactlyOnce(): void
    {
        // AUTH-24: 10 символів без омоглифів, повертається один раз.
        $credentials = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '0671234567',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));

        self::assertTrue($credentials->passwordGenerated);
        self::assertNotNull($credentials->passwordPlain);
        self::assertSame(10, \strlen($credentials->passwordPlain));
        self::assertSame(
            \strlen($credentials->passwordPlain),
            strspn($credentials->passwordPlain, DriverPasswordGenerator::ALPHABET),
        );

        // Повторно прочитати пароль неможливо — у сховищі лише хеш.
        $account = $this->env->accounts->findByLogin('+380671234567');
        self::assertNotNull($account);
        self::assertStringStartsWith('$argon2id$', $account->passwordHash());
        self::assertStringNotContainsString($credentials->passwordPlain, $account->passwordHash());
    }

    public function testGeneratedDriverPasswordActuallyWorksForLogin(): void
    {
        $credentials = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '067 123 45 67',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));

        $result = $this->env->authentication->login(
            ClientType::DriverWeb,
            '(067) 123-45-67',
            (string) $credentials->passwordPlain,
        );

        self::assertSame($credentials->profile->accountId, $result->profile->accountId);
    }

    public function testMustChangePasswordFlagIsSetForGeneratedPassword(): void
    {
        // 10.6: mustChangePassword = true після генерації пароля.
        $generated = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '0671234567',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));

        $chosen = $this->env->provisioner->create(new CreatePartnerAccount(
            login: 'sales@postachalnyk.ua',
            role: PartnerRole::SupplierAdmin,
            supplierId: 'sp-01',
            passwordPlain: 'Postach2026',
        ));

        self::assertTrue($generated->profile->mustChangePassword);
        self::assertFalse($chosen->profile->mustChangePassword);
    }

    public function testDuplicatePhoneIsRejectedAcrossDifferentSuppliers(): void
    {
        // Крайовий випадок 3.3.2: телефон унікальний у межах усього контуру.
        $this->env->provisioner->create(new CreatePartnerAccount(
            login: '067 123 45 67',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));

        try {
            $this->env->provisioner->create(new CreatePartnerAccount(
                login: '+380671234567',
                role: PartnerRole::Driver,
                supplierId: 'sp-02',
            ));
            self::fail('Очікувався LoginAlreadyTakenException.');
        } catch (LoginAlreadyTakenException $exception) {
            self::assertSame('PARTNER_ACCOUNT_LOGIN_TAKEN', $exception->errorCode());
            self::assertSame(409, $exception->httpStatus());
            self::assertSame('+380671234567', $exception->login);
        }
    }

    public function testSupplierAccountRejectsWeakPassword(): void
    {
        // AUTH-21 + AUTH-13.
        try {
            $this->env->provisioner->create(new CreatePartnerAccount(
                login: 'sales@postachalnyk.ua',
                role: PartnerRole::SupplierAdmin,
                supplierId: 'sp-01',
                passwordPlain: 'qwerty',
            ));
            self::fail('Очікувався WeakPasswordException.');
        } catch (WeakPasswordException $exception) {
            self::assertSame('AUTH_WEAK_PASSWORD', $exception->errorCode());
            self::assertNotEmpty($exception->violations);
        }

        self::assertNull($this->env->accounts->findByLogin('sales@postachalnyk.ua'));
    }

    public function testDriverLoginMustBeAPhoneNotAnEmail(): void
    {
        $this->expectException(InvalidLoginFormatException::class);

        $this->env->provisioner->create(new CreatePartnerAccount(
            login: 'driver@postachalnyk.ua',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));
    }

    public function testRegeneratedPasswordInvalidatesTheOldOneImmediately(): void
    {
        // AUTH-25 + критерій 3.9.5.
        $created = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '0671234567',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));
        $oldPassword = (string) $created->passwordPlain;

        $regenerated = $this->env->provisioner->regeneratePassword($created->profile->accountId);
        $newPassword = (string) $regenerated->passwordPlain;

        self::assertNotSame($oldPassword, $newPassword);

        try {
            $this->env->authentication->login(ClientType::DriverWeb, '0671234567', $oldPassword);
            self::fail('Старий пароль має бути недійсним негайно.');
        } catch (InvalidCredentialsException) {
            // очікувано
        }

        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', $newPassword);
        self::assertSame($created->profile->accountId, $result->profile->accountId);
    }

    public function testRegenerationRevokesAllDriverSessions(): void
    {
        // AUTH-25 / AUTH-32.
        $created = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '0671234567',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));

        $session = $this->env->authentication->login(
            ClientType::DriverWeb,
            '0671234567',
            (string) $created->passwordPlain,
        );

        self::assertCount(1, $this->env->refreshTokens->findActiveForAccount($created->profile->accountId, $this->env->clock->now()));

        $this->env->provisioner->regeneratePassword($created->profile->accountId);

        self::assertSame([], $this->env->refreshTokens->findActiveForAccount($created->profile->accountId, $this->env->clock->now()));
        self::assertNotSame('', $session->refreshToken);
    }

    public function testRegenerationOfUnknownAccountFails(): void
    {
        $this->expectException(AccountNotFoundException::class);

        $this->env->provisioner->regeneratePassword('невідомий-id');
    }

    public function testSuspendingSupplierDisablesAllItsAccounts(): void
    {
        // AUTH-28: постачальник, його оператор і водії втрачають логін.
        $this->env->givenSupplier(email: 'admin@postachalnyk.ua', password: 'Postach2026', role: PartnerRole::SupplierAdmin, supplierId: 'sp-01');
        $this->env->givenSupplier(email: 'operator@postachalnyk.ua', password: 'Postach2026', role: PartnerRole::SupplierOperator, supplierId: 'sp-01');
        $this->env->givenDriver(phone: '067 123 45 67', supplierId: 'sp-01', driverProfileId: 'du-1');
        $otherSupplierDriver = $this->env->givenDriver(phone: '050 987 65 43', supplierId: 'sp-02', driverProfileId: 'du-2');

        $affected = $this->env->provisioner->suspendSupplier('sp-01');

        self::assertSame(3, $affected);

        foreach ($this->env->accounts->findBySupplierId('sp-01') as $account) {
            self::assertFalse($account->isActive());
        }

        self::assertTrue($otherSupplierDriver->isActive(), 'Водій іншого постачальника не має постраждати.');
    }

    public function testResumingSupplierRestoresLogin(): void
    {
        $this->env->givenDriver(phone: '067 123 45 67', password: 'Rmp7dK2xTq', supplierId: 'sp-01');
        $this->env->provisioner->suspendSupplier('sp-01');

        self::assertSame(1, $this->env->provisioner->resumeSupplier('sp-01'));

        $result = $this->env->authentication->login(ClientType::DriverWeb, '0671234567', 'Rmp7dK2xTq');
        self::assertNotSame('', $result->accessToken);
    }

    public function testChangingDriverPhoneChangesLoginAndForcesNewPassword(): void
    {
        // Крайовий випадок 3.3.2: зміна номера = зміна логіна + перегенерація.
        $created = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '067 123 45 67',
            role: PartnerRole::Driver,
            supplierId: 'sp-01',
        ));
        $oldPassword = (string) $created->passwordPlain;

        $updated = $this->env->provisioner->changeDriverLogin($created->profile->accountId, '050 987 65 43');

        self::assertSame('+380509876543', $updated->profile->login);
        self::assertNull($this->env->accounts->findByLogin('+380671234567'));
        self::assertNotSame($oldPassword, $updated->passwordPlain);

        $result = $this->env->authentication->login(ClientType::DriverWeb, '0509876543', (string) $updated->passwordPlain);
        self::assertSame($created->profile->accountId, $result->profile->accountId);
    }

    public function testCredentialsPayloadHidesPasswordWhenItWasNotGenerated(): void
    {
        // AUTH-61: заданий постачальником пароль не повертається у відповіді.
        $credentials = $this->env->provisioner->create(new CreatePartnerAccount(
            login: 'sales@postachalnyk.ua',
            role: PartnerRole::SupplierAdmin,
            supplierId: 'sp-01',
            passwordPlain: 'Postach2026',
        ));

        $payload = $credentials->toArray();

        self::assertArrayNotHasKey('passwordPlain', $payload);
        self::assertFalse($payload['passwordGenerated']);
        self::assertStringNotContainsString('Postach2026', (string) json_encode($payload));
    }

    public function testAccountKeepsSupplierScopeAndDriverProfileLink(): void
    {
        // AUTH-29 / DATA-35: звʼязок акаунт → профіль водія.
        $credentials = $this->env->provisioner->create(new CreatePartnerAccount(
            login: '0671234567',
            role: PartnerRole::Driver,
            supplierId: 'sp-77',
            driverProfileId: 'du-77',
        ));

        self::assertSame('sp-77', $credentials->profile->supplierId);
        self::assertSame('du-77', $credentials->profile->driverProfileId);
        self::assertSame('partner', $credentials->toArray()['contour']);
    }
}
