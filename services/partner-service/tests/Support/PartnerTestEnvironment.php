<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Service\DriverService;
use App\Domain\Service\SupplierService;
use App\Domain\Service\VehicleService;
use App\Domain\Supplier\Supplier;
use App\Infrastructure\InMemory\FixedClock;
use App\Infrastructure\InMemory\InMemoryBookingQueryPort;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemoryPartnerAccountGateway;
use App\Infrastructure\InMemory\InMemoryPartnerUserRepository;
use App\Infrastructure\InMemory\InMemorySupplierRepository;
use App\Infrastructure\InMemory\InMemoryVehicleRepository;
use App\Infrastructure\InMemory\SequenceIdGenerator;
use App\Infrastructure\Security\SecurePasswordGenerator;

/**
 * Складання доменних сервісів на InMemory-реалізаціях.
 *
 * Уся доменна логіка тестується БЕЗ MongoDB, Redis і RabbitMQ —
 * це вимога до юніт-тестів проєкту.
 */
final class PartnerTestEnvironment
{
    public InMemorySupplierRepository $suppliers;
    public InMemoryPartnerUserRepository $users;
    public InMemoryVehicleRepository $vehicles;
    public InMemoryPartnerAccountGateway $accounts;
    public InMemoryEventPublisher $events;
    public InMemoryBookingQueryPort $bookings;
    public FixedClock $clock;

    public SupplierService $supplierService;
    public VehicleService $vehicleService;
    public DriverService $driverService;

    public function __construct()
    {
        $this->suppliers = new InMemorySupplierRepository();
        $this->users = new InMemoryPartnerUserRepository();
        $this->vehicles = new InMemoryVehicleRepository();
        $this->accounts = new InMemoryPartnerAccountGateway();
        $this->events = new InMemoryEventPublisher();
        $this->bookings = new InMemoryBookingQueryPort();
        $this->clock = new FixedClock('2026-08-27T09:00:00+00:00');

        $this->supplierService = new SupplierService(
            suppliers: $this->suppliers,
            accounts: $this->accounts,
            events: $this->events,
            bookings: $this->bookings,
            ids: new SequenceIdGenerator('sp'),
            clock: $this->clock,
        );

        $this->vehicleService = new VehicleService(
            vehicles: $this->vehicles,
            bookings: $this->bookings,
            ids: new SequenceIdGenerator('vh'),
            clock: $this->clock,
        );

        $this->driverService = new DriverService(
            users: $this->users,
            suppliers: $this->suppliers,
            vehicles: $this->vehicles,
            accounts: $this->accounts,
            passwords: new SecurePasswordGenerator(),
            events: $this->events,
            ids: new SequenceIdGenerator('du'),
            clock: $this->clock,
        );
    }

    /**
     * Створює постачальника з мінімальними даними.
     */
    public function givenSupplier(string $name, ?string $edrpou = null): Supplier
    {
        return $this->supplierService->create(name: $name, edrpou: $edrpou);
    }
}
