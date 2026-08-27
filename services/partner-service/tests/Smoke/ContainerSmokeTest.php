<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Domain\Booking\BookingQueryPort;
use App\Domain\Event\EventPublisher;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\PartnerUser\PartnerUserRepository;
use App\Domain\Security\PasswordGenerator;
use App\Domain\Service\DriverService;
use App\Domain\Service\SupplierService;
use App\Domain\Service\VehicleService;
use App\Domain\Supplier\SupplierRepository;
use App\Domain\Vehicle\VehicleRepository;
use App\Infrastructure\InMemory\InMemoryPartnerAccountGateway;
use App\Infrastructure\InMemory\InMemoryPartnerUserRepository;
use App\Infrastructure\InMemory\InMemorySupplierRepository;
use App\Infrastructure\InMemory\InMemoryVehicleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Контейнер має збиратися і без MongoDB, і без RabbitMQ: у dev/test
 * порти доменного шару вказують на InMemory-реалізації.
 */
final class ContainerSmokeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function domainServices(): iterable
    {
        yield 'постачальники' => [SupplierService::class];
        yield 'автопарк' => [VehicleService::class];
        yield 'водії' => [DriverService::class];
        yield 'генератор паролів' => [PasswordGenerator::class];
        yield 'публікатор подій' => [EventPublisher::class];
        yield 'порт бронювань' => [BookingQueryPort::class];
    }

    /**
     * @param class-string $serviceId
     */
    #[DataProvider('domainServices')]
    public function testDomainServicesAreWired(string $serviceId): void
    {
        self::bootKernel();

        self::assertInstanceOf($serviceId, self::getContainer()->get($serviceId));
    }

    public function testPortsPointToInMemoryImplementationsInTestEnvironment(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(InMemorySupplierRepository::class, $container->get(SupplierRepository::class));
        self::assertInstanceOf(InMemoryPartnerUserRepository::class, $container->get(PartnerUserRepository::class));
        self::assertInstanceOf(InMemoryVehicleRepository::class, $container->get(VehicleRepository::class));
        self::assertInstanceOf(InMemoryPartnerAccountGateway::class, $container->get(PartnerAccountGateway::class));
    }

    /**
     * Наскрізна перевірка на зібраному контейнері: постачальник → авто → водій.
     */
    public function testEndToEndFlowWorksOnWiredServices(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var SupplierService $suppliers */
        $suppliers = $container->get(SupplierService::class);
        /** @var VehicleService $vehicles */
        $vehicles = $container->get(VehicleService::class);
        /** @var DriverService $drivers */
        $drivers = $container->get(DriverService::class);

        $supplier = $suppliers->create('ТОВ «Смоук Транс»', '87654321');
        $vehicle = $vehicles->create($supplier->id(), 'ва 1234 ср', 12.5, 'Renault');
        $credentials = $drivers->createDriver(
            supplierId: $supplier->id(),
            phone: '050 111 22 33',
            firstName: 'Микола',
            lastName: 'Гриценко',
            defaultVehicleId: $vehicle->id(),
        );

        self::assertSame('ВА1234СР', $vehicle->plateNumber());
        self::assertSame('+380501112233', $credentials->login);
        self::assertSame(12, \strlen($credentials->password));
        self::assertCount(1, $drivers->list($supplier->id()));
    }
}
