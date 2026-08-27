<?php

declare(strict_types=1);

namespace App\Tests\Domain\Service;

use App\Domain\Event\DriverCreated;
use App\Domain\Identity\PartnerRole;
use App\Domain\Service\DriverService;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use App\Infrastructure\Security\SecurePasswordGenerator;
use App\Tests\Support\PartnerTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Водії постачальника: SUP-DRV-01…SUP-DRV-05, DATA-17, DATA-35.
 */
#[CoversClass(DriverService::class)]
final class DriverServiceTest extends TestCase
{
    private PartnerTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new PartnerTestEnvironment();
        $this->env->givenSupplier('ТОВ «Логістик Плюс»', '12345678');
        $this->env->givenSupplier('ФОП Петренко');
    }

    public function testCreatesDriverWithNormalizedPhoneAsLogin(): void
    {
        $credentials = $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '067 111 22 33',
            firstName: 'Іван',
            lastName: 'Коваль',
        );

        self::assertSame('+380671112233', $credentials->driver->phone());
        self::assertSame('+380671112233', $credentials->login, 'Телефон водія одночасно є логіном (SUP-DRV-03).');
        self::assertTrue($credentials->driver->isDriver());
        self::assertTrue($credentials->driver->isActive());
        self::assertSame('Коваль Іван', $credentials->driver->fullName());
    }

    /**
     * SUP-DRV-03: пароль генерується сервером і повертається рівно один раз.
     */
    public function testGeneratedPasswordIsReturnedOnceAndNeverStoredInProfile(): void
    {
        $credentials = $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '+380671112233',
            firstName: 'Іван',
            lastName: 'Коваль',
        );

        self::assertSame(SecurePasswordGenerator::DEFAULT_LENGTH, \strlen($credentials->password));

        // DATA-35: у профілі partner_users немає жодного поля з паролем.
        $stored = $this->env->users->findById($credentials->driver->id());
        self::assertNotNull($stored);

        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass($stored))->getProperties(),
        );
        self::assertNotContains('password', $properties);
        self::assertNotContains('passwordHash', $properties);
    }

    /**
     * DATA-35: креденшли створює identity-partner-service, рівно одна роль.
     */
    public function testAccountIsCreatedInIdentityContourWithDriverRole(): void
    {
        $credentials = $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '+380671112233',
            firstName: 'Іван',
            lastName: 'Коваль',
        );

        $account = $this->env->accounts->findByLogin('+380671112233');

        self::assertNotNull($account);
        self::assertSame(PartnerRole::Driver, $account['role']);
        self::assertSame('sp-0001', $account['supplierId']);
        self::assertSame($credentials->driver->id(), $account['driverProfileId']);
        self::assertSame($credentials->driver->accountId(), $account['id']);
        self::assertTrue($account['mustChangePassword']);
        self::assertSame($credentials->password, $account['password']);
    }

    /**
     * SUP-DRV-03: подія DriverCreated — привід для SMS у notification-service.
     */
    public function testPublishesCanonicalDriverCreatedEvent(): void
    {
        $credentials = $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '+380671112233',
            firstName: 'Іван',
            lastName: 'Коваль',
        );

        $events = $this->env->events->ofType('DriverCreated');

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(DriverCreated::class, $event);
        self::assertSame($credentials->driver->id(), $event->aggregateId());
        self::assertSame('+380671112233', $event->payload()['login']);
        self::assertSame($credentials->password, $event->payload()['password']);
        self::assertFalse($event->payload()['passwordRegenerated']);
        self::assertSame('ТОВ «Логістик Плюс»', $event->payload()['supplierName']);
    }

    /**
     * DATA-17 / SUP-DRV-02: телефон водія унікальний ГЛОБАЛЬНО —
     * навіть якщо другий водій належить іншому постачальнику.
     */
    public function testDriverPhoneIsUniqueAcrossAllSuppliers(): void
    {
        $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');

        try {
            $this->env->driverService->createDriver('sp-0002', '+380671112233', 'Петро', 'Шевчук');
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('DRIVER_PHONE_DUPLICATE', $e->errorCode());
            self::assertSame('Водій з таким телефоном уже зареєстрований.', $e->getMessage());
        }
    }

    /**
     * Дублікат ловиться після нормалізації: інший формат того самого
     * номера — це той самий логін.
     */
    public function testDuplicatePhoneIsDetectedRegardlessOfInputFormat(): void
    {
        $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');

        $this->expectException(ConflictException::class);

        $this->env->driverService->createDriver('sp-0001', '067-111-22-33', 'Петро', 'Шевчук');
    }

    public function testFailedDuplicateCreationDoesNotLeaveOrphanAccount(): void
    {
        $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');
        $accountsBefore = $this->env->accounts->count();

        try {
            $this->env->driverService->createDriver('sp-0002', '+380671112233', 'Петро', 'Шевчук');
        } catch (ConflictException) {
            // очікувано
        }

        self::assertSame($accountsBefore, $this->env->accounts->count());
        self::assertCount(1, $this->env->events->ofType('DriverCreated'));
    }

    /**
     * SUP-DRV-04: перегенерація пароля — новий пароль, нова подія, старий
     * пароль інвалідовано в контурі ідентичності.
     */
    public function testRegeneratePasswordIssuesNewPasswordAndInvalidatesOld(): void
    {
        $created = $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');
        $accountId = $created->driver->accountId();

        $regenerated = $this->env->driverService->regeneratePassword('sp-0001', $created->driver->id());

        self::assertNotSame($created->password, $regenerated->password);
        self::assertSame($regenerated->password, $this->env->accounts->passwordOf($accountId));
        self::assertSame('+380671112233', $regenerated->login);

        $events = $this->env->events->ofType('DriverCreated');
        self::assertCount(2, $events);
        self::assertTrue($events[1]->payload()['passwordRegenerated']);
    }

    /**
     * SUP-DRV-05: деактивація блокує вхід у driver-web, історія зберігається.
     */
    public function testDeactivationBlocksLoginButKeepsProfile(): void
    {
        $created = $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');

        $this->env->driverService->deactivate('sp-0001', $created->driver->id());

        self::assertFalse($this->env->accounts->isActive($created->driver->accountId()));
        self::assertFalse($this->env->driverService->getDriver('sp-0001', $created->driver->id())->isActive());
        self::assertCount(1, $this->env->driverService->list('sp-0001'));
        self::assertCount(0, $this->env->driverService->list('sp-0001', includeInactive: false));
    }

    public function testReactivationRestoresLogin(): void
    {
        $created = $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');
        $this->env->driverService->deactivate('sp-0001', $created->driver->id());

        $this->env->driverService->activate('sp-0001', $created->driver->id());

        self::assertTrue($this->env->accounts->isActive($created->driver->accountId()));
        self::assertTrue($this->env->driverService->getDriver('sp-0001', $created->driver->id())->isActive());
    }

    public function testDriverOfAnotherSupplierIsNotVisible(): void
    {
        $created = $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');

        $this->expectException(NotFoundException::class);

        $this->env->driverService->getDriver('sp-0002', $created->driver->id());
    }

    public function testDriverListIsScopedToSupplier(): void
    {
        $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');
        $this->env->driverService->createDriver('sp-0002', '+380502223344', 'Петро', 'Шевчук');

        self::assertCount(1, $this->env->driverService->list('sp-0001'));
        self::assertCount(1, $this->env->driverService->list('sp-0002'));
    }

    public function testMissingNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->env->driverService->createDriver('sp-0001', '+380671112233', '  ', 'Коваль');
    }

    public function testVehicleFromAnotherSupplierCannotBeAssignedToDriver(): void
    {
        $foreignVehicle = $this->env->vehicleService->create('sp-0002', 'AA1234BB', 12.0);

        $this->expectException(ValidationException::class);

        $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '+380671112233',
            firstName: 'Іван',
            lastName: 'Коваль',
            defaultVehicleId: $foreignVehicle->id(),
        );
    }

    public function testOwnVehicleCanBeAssignedAsDefault(): void
    {
        $vehicle = $this->env->vehicleService->create('sp-0001', 'AA1234BB', 12.0);

        $credentials = $this->env->driverService->createDriver(
            supplierId: 'sp-0001',
            phone: '+380671112233',
            firstName: 'Іван',
            lastName: 'Коваль',
            defaultVehicleId: $vehicle->id(),
        );

        self::assertSame($vehicle->id(), $credentials->driver->defaultVehicleId());
    }

    /**
     * SUP-02: у призупиненого постачальника логіни заблоковані,
     * тому нових водіїв створювати не можна.
     */
    public function testSuspendedSupplierCannotCreateDrivers(): void
    {
        $this->env->supplierService->suspend('sp-0001', 'Заборгованість');

        try {
            $this->env->driverService->createDriver('sp-0001', '+380671112233', 'Іван', 'Коваль');
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('SUPPLIER_SUSPENDED', $e->errorCode());
        }
    }

    public function testUnknownSupplierIsRejected(): void
    {
        $this->expectException(NotFoundException::class);

        $this->env->driverService->createDriver('sp-невідомий', '+380671112233', 'Іван', 'Коваль');
    }
}
