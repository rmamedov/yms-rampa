<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Event\DriverCreated;
use App\Domain\Event\EventPublisher;
use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Identity\PartnerRole;
use App\Domain\PartnerUser\PartnerUser;
use App\Domain\PartnerUser\PartnerUserRepository;
use App\Domain\PartnerUser\PartnerUserType;
use App\Domain\Security\PasswordGenerator;
use App\Domain\Shared\Clock;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\IdGenerator;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\PhoneNormalizer;
use App\Domain\Shared\ValidationException;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierRepository;
use App\Domain\Vehicle\VehicleRepository;

/**
 * Водії постачальника (SUP-DRV-01…SUP-DRV-05).
 *
 * Розподіл відповідальності (DATA-35): тут — бізнес-профіль у колекції
 * `partner_users`; логін/пароль/роль створює identity-partner-service через
 * порт PartnerAccountGateway; SMS із паролем надсилає notification-service
 * за канонічною подією DriverCreated.
 */
final readonly class DriverService
{
    public function __construct(
        private PartnerUserRepository $users,
        private SupplierRepository $suppliers,
        private VehicleRepository $vehicles,
        private PartnerAccountGateway $accounts,
        private PasswordGenerator $passwords,
        private EventPublisher $events,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /**
     * SUP-DRV-02, SUP-DRV-03: створення водія.
     *
     * Телефон нормалізується до +380XXXXXXXXX і перевіряється на ГЛОБАЛЬНУ
     * унікальність серед активних водіїв (DATA-17) — саме він стає логіном.
     * Пароль генерується сервером, повертається рівно один раз і
     * публікується в події DriverCreated для SMS.
     *
     * ПОРЯДОК КРОКІВ — це і є захист від «осиротілого» профілю: облікові дані
     * створюються ПЕРШИМИ, і лише після підтвердження від контуру ідентичності
     * зʼявляється запис у `partner_users`. Якщо сусід недоступний або відмовив,
     * виняток летить ще до `users->save()`, тож водія, який існує в довіднику,
     * але не може увійти, не буває. Компенсація нижче закриває дзеркальний
     * випадок: акаунт створено, а профіль записати не вдалося.
     */
    public function createDriver(
        string $supplierId,
        string $phone,
        string $firstName,
        string $lastName,
        ?string $defaultVehicleId = null,
    ): DriverCredentials {
        $supplier = $this->requireSupplier($supplierId);
        $this->assertSupplierActive($supplier);
        $now = $this->clock->now();
        $normalizedPhone = PhoneNormalizer::normalize($phone);

        $this->assertPhoneFree($normalizedPhone, null);
        $this->assertVehicleBelongsToSupplier($supplierId, $defaultVehicleId);

        $driverId = $this->ids->generate();
        $password = $this->passwords->generate();

        $accountId = $this->accounts->createAccount(new CreateAccountCommand(
            login: $normalizedPhone,
            password: $password,
            role: PartnerRole::Driver,
            supplierId: $supplierId,
            driverProfileId: $driverId,
            mustChangePassword: true,
        ));

        $driver = PartnerUser::driver(
            id: $driverId,
            accountId: $accountId,
            supplierId: $supplierId,
            phone: $normalizedPhone,
            firstName: $firstName,
            lastName: $lastName,
            defaultVehicleId: $defaultVehicleId,
            createdAt: $now,
        );

        try {
            $this->users->save($driver);
        } catch (\Throwable $e) {
            // Компенсація: акаунт уже створено в контурі ідентичності,
            // але профілю немає — блокуємо логін, щоб не лишити «сироту».
            try {
                $this->accounts->setAccountActive($accountId, false);
            } catch (\Throwable) {
                // Компенсація теж не вдалася (сусід недоступний). Ковтаємо саме
                // цю помилку, а не первісну: користувач має побачити ПРИЧИНУ
                // збою запису профілю, інакше справжня аварія загубиться за
                // «сервіс облікових записів недоступний». Слід невдалої
                // компенсації лишається в журналі шлюзу.
            }

            throw $e;
        }

        $this->events->publish(new DriverCreated(
            driverProfileId: $driver->id(),
            accountId: $accountId,
            supplierId: $supplierId,
            supplierName: $supplier->name(),
            phone: $normalizedPhone,
            firstName: $driver->firstName() ?? '',
            lastName: $driver->lastName() ?? '',
            password: $password,
            occurredAt: $now,
        ));

        return new DriverCredentials($driver, $normalizedPhone, $password);
    }

    /**
     * SUP-DRV-04: перегенерація пароля. Старий пароль інвалідовується,
     * активні сесії водія завершуються (це робить identity-partner-service),
     * новий пароль показується одноразово і дублюється SMS.
     *
     * У SMS і в модалку йде пароль, який ПОВЕРНУВ шлюз, а не той, що ми
     * запропонували: паролем водія володіє контур ідентичності (AUTH-25) і
     * може згенерувати власний. Показати одне, а зберегти інше — це той самий
     * «водій не може увійти», лише на крок пізніше.
     */
    public function regeneratePassword(string $supplierId, string $driverId): DriverCredentials
    {
        $driver = $this->getDriver($supplierId, $driverId);
        $supplier = $this->requireSupplier($supplierId);
        $now = $this->clock->now();

        $password = $this->accounts->resetPassword($driver->accountId(), $this->passwords->generate());

        $this->events->publish(new DriverCreated(
            driverProfileId: $driver->id(),
            accountId: $driver->accountId(),
            supplierId: $supplierId,
            supplierName: $supplier->name(),
            phone: (string) $driver->phone(),
            firstName: $driver->firstName() ?? '',
            lastName: $driver->lastName() ?? '',
            password: $password,
            occurredAt: $now,
            passwordRegenerated: true,
        ));

        return new DriverCredentials($driver, (string) $driver->phone(), $password);
    }

    /**
     * SUP-DRV-05: деактивація — вхід у driver-web блокується, історія
     * зберігається, повторна активація можлива.
     */
    public function deactivate(string $supplierId, string $driverId): PartnerUser
    {
        $driver = $this->getDriver($supplierId, $driverId);

        if ($driver->deactivate($this->clock->now())) {
            $this->users->save($driver);
            $this->accounts->setAccountActive($driver->accountId(), false);
        }

        return $driver;
    }

    public function activate(string $supplierId, string $driverId): PartnerUser
    {
        $driver = $this->getDriver($supplierId, $driverId);

        if ($driver->activate($this->clock->now())) {
            $this->users->save($driver);
            $this->accounts->setAccountActive($driver->accountId(), true);
        }

        return $driver;
    }

    public function changePhone(string $supplierId, string $driverId, string $phone): PartnerUser
    {
        $driver = $this->getDriver($supplierId, $driverId);
        $normalized = PhoneNormalizer::normalize($phone);

        $this->assertPhoneFree($normalized, $driver->id());
        $driver->changePhone($normalized, $this->clock->now());
        $this->users->save($driver);

        return $driver;
    }

    public function assignVehicle(string $supplierId, string $driverId, ?string $vehicleId): PartnerUser
    {
        $driver = $this->getDriver($supplierId, $driverId);
        $this->assertVehicleBelongsToSupplier($supplierId, $vehicleId);
        $driver->assignDefaultVehicle($vehicleId, $this->clock->now());
        $this->users->save($driver);

        return $driver;
    }

    /**
     * SUP-DRV-01: список водіїв постачальника.
     *
     * @return list<PartnerUser>
     */
    public function list(string $supplierId, bool $includeInactive = true): array
    {
        return $this->users->listBySupplier($supplierId, PartnerUserType::Driver, $includeInactive);
    }

    public function getDriver(string $supplierId, string $driverId): PartnerUser
    {
        $driver = $this->users->findById($driverId);

        if (null === $driver || !$driver->isDriver() || !$driver->belongsTo($supplierId)) {
            throw new NotFoundException(
                \sprintf('Водія «%s» не знайдено.', $driverId),
                'DRIVER_NOT_FOUND',
            );
        }

        return $driver;
    }

    /**
     * DATA-17: телефон водія унікальний глобально — серед УСІХ активних
     * водіїв контуру, а не лише в межах одного постачальника.
     */
    private function assertPhoneFree(string $phone, ?string $exceptId): void
    {
        $existing = $this->users->findDriverByPhone($phone);

        if (null !== $existing && $existing->id() !== $exceptId) {
            throw new ConflictException(
                'Водій з таким телефоном уже зареєстрований.',
                'DRIVER_PHONE_DUPLICATE',
            );
        }
    }

    private function assertVehicleBelongsToSupplier(string $supplierId, ?string $vehicleId): void
    {
        if (null === $vehicleId || '' === trim($vehicleId)) {
            return;
        }

        $vehicle = $this->vehicles->findById($vehicleId);

        if (null === $vehicle || $vehicle->supplierId() !== $supplierId) {
            throw new ValidationException(
                'Авто не знайдено у вашому довіднику.',
                'VEHICLE_NOT_FOUND',
            );
        }
    }

    private function requireSupplier(string $supplierId): Supplier
    {
        return $this->suppliers->findById($supplierId)
            ?? throw new NotFoundException(
                \sprintf('Постачальника «%s» не знайдено.', $supplierId),
                'SUPPLIER_NOT_FOUND',
            );
    }

    /**
     * SUP-02: у призупиненого постачальника логін заблоковано для всіх
     * акаунтів, тому створювати нові облікові записи водіїв немає сенсу.
     */
    private function assertSupplierActive(Supplier $supplier): void
    {
        if (!$supplier->isActive()) {
            throw new ConflictException(
                'Обліковий запис постачальника призупинено.',
                'SUPPLIER_SUSPENDED',
            );
        }
    }
}
