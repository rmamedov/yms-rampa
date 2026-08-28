<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Booking;

use App\Domain\Booking\BookingQueryUnavailableException;
use App\Domain\Service\SupplierService;
use App\Domain\Shared\ConflictException;
use App\Infrastructure\Booking\HttpBookingQueryPort;
use App\Infrastructure\InMemory\FixedClock;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemoryPartnerAccountGateway;
use App\Infrastructure\InMemory\InMemorySupplierRepository;
use App\Infrastructure\InMemory\SequenceIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Порт до booking-service (SUP-06).
 *
 * Мережі тут немає: транспорт підмінений MockHttpClient, а тіла відповідей —
 * фікстури РЕАЛЬНОГО контракту сусіда (InternalSupplierBookingController
 * booking-service).
 *
 * Головне, що перевіряють ці тести: недоступний сусід НЕ перетворюється на
 * «бронювання є». Саме та підміна робила довідник постачальників невидаляним.
 */
#[CoversClass(HttpBookingQueryPort::class)]
#[CoversClass(BookingQueryUnavailableException::class)]
final class HttpBookingQueryPortTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:8081';
    private const SUPPLIER_ID = 'sp-0001';

    // --- контракт сусіда ----------------------------------------------------

    public function testAsksNeighbourOnInternalRouteAndReturnsFalse(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse($this->body(false));
        });

        self::assertFalse($this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID));
        self::assertSame('GET', $captured['method']);
        self::assertSame(
            self::BASE_URL.'/internal/v1/bookings/suppliers/'.self::SUPPLIER_ID,
            $captured['url'],
        );
        // Службовий маршрут, а не адмінський API: через шлюз /api/ він не ходить.
        self::assertStringNotContainsString('/api/', $captured['url']);
    }

    public function testReturnsTrueWhenNeighbourReportsBookings(): void
    {
        $port = $this->port(new MockHttpClient(new MockResponse($this->body(true))));

        self::assertTrue($port->supplierHasAnyBookings(self::SUPPLIER_ID));
    }

    /** Ідентифікатор іде в шлях екранованим — він приходить ззовні. */
    public function testEscapesSupplierIdInPath(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->body(false));
        });

        $this->port($client)->supplierHasAnyBookings('sp/../../etc');

        self::assertSame(
            self::BASE_URL.'/internal/v1/bookings/suppliers/sp%2F..%2F..%2Fetc',
            $captured,
        );
    }

    // --- сусід недоступний: свідомий запасний варіант ------------------------

    public function testUnreachableNeighbourRaises503WithExplicitReason(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });

        try {
            $this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID);
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(BookingQueryUnavailableException::ERROR_CODE, $error->errorCode());
            self::assertSame(503, $error->httpStatus());
            // Повідомлення має говорити про недоступність сусіда, а не
            // звинувачувати постачальника в неіснуючій історії бронювань.
            self::assertStringContainsString('Сервіс бронювань тимчасово недоступний', $error->getMessage());
            self::assertStringContainsString('постачальника не видалено', $error->getMessage());
            self::assertStringNotContainsString('історією бронювань', $error->getMessage());
        }
    }

    public function testServerErrorFromNeighbourRaises503(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 502]));

        $this->expectException(BookingQueryUnavailableException::class);
        $this->expectExceptionMessage('HTTP 502');

        $this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID);
    }

    /**
     * 404 контракт сусіда не передбачає: постачальник без жодного бронювання —
     * це `hasAnyBookings: false`, а не «не знайдено». Тому 404 — теж аварія,
     * і мовчазне «бронювань немає» з нього робити не можна.
     */
    public function testNotFoundIsTreatedAsFailureAndNotAsAbsenceOfBookings(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(BookingQueryUnavailableException::class);

        $this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID);
    }

    public function testUnparsableBodyRaises502(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>502 Bad Gateway</html>'));

        try {
            $this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID);
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(BookingQueryUnavailableException::BAD_RESPONSE_CODE, $error->errorCode());
            self::assertSame(502, $error->httpStatus());
        }
    }

    public function testMissingFlagInBodyRaises502(): void
    {
        $client = new MockHttpClient(new MockResponse('{"supplierId":"sp-0001"}'));

        try {
            $this->port($client)->supplierHasAnyBookings(self::SUPPLIER_ID);
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(BookingQueryUnavailableException::BAD_RESPONSE_CODE, $error->errorCode());
            self::assertStringContainsString('hasAnyBookings', $error->getMessage());
        }
    }

    // --- SUP-VEH-04: перевірка авто за парою «постачальник + номер» ---------

    /**
     * ISSUE-22: питання ставиться службовим маршрутом за держномером, і «немає
     * активних бронювань» означає саме це. Раніше метод був заглушкою, яка
     * завжди відповідала «є», тому жодне авто не видалялося.
     */
    public function testAsksNeighbourAboutVehicleByPlateAndReturnsFalse(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse($this->vehicleBody(false));
        });

        self::assertFalse($this->port($client)->vehicleHasActiveBookings(self::SUPPLIER_ID, 'AA1234BB'));
        self::assertSame('GET', $captured['method']);
        self::assertSame(
            self::BASE_URL.'/internal/v1/bookings/suppliers/'.self::SUPPLIER_ID.'/vehicles/AA1234BB',
            $captured['url'],
        );
        self::assertStringNotContainsString('/api/', $captured['url']);
    }

    public function testReturnsTrueWhenNeighbourReportsActiveBookingsForVehicle(): void
    {
        $port = $this->port(new MockHttpClient(new MockResponse($this->vehicleBody(true))));

        self::assertTrue($port->vehicleHasActiveBookings(self::SUPPLIER_ID, 'AA1234BB'));
    }

    /** Номер приходить ззовні, тому екранується так само, як ідентифікатор. */
    public function testEscapesPlateNumberInPath(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->vehicleBody(false));
        });

        $this->port($client)->vehicleHasActiveBookings(self::SUPPLIER_ID, 'АА../../etc');

        self::assertStringNotContainsString('../', $captured);
    }

    /**
     * Недоступний сусід НЕ означає «бронювань немає»: авто не видаляється,
     * але причина чесна (503), і вона потрапляє в журнал.
     */
    public function testUnreachableNeighbourDoesNotSilentlyAllowVehicleDeletion(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $port = new HttpBookingQueryPort(
            new MockHttpClient(static function (): MockResponse {
                throw new TransportException('Connection refused');
            }),
            self::BASE_URL,
            logger: $logger,
        );

        try {
            $port->vehicleHasActiveBookings(self::SUPPLIER_ID, 'AA1234BB');
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringContainsString('авто не видалено', $error->getMessage());
        }

        self::assertCount(1, $logger->warnings);
    }

    public function testMissingVehicleFlagInBodyRaises502(): void
    {
        $client = new MockHttpClient(new MockResponse('{"supplierId":"sp-0001","plateNumber":"AA1234BB"}'));

        try {
            $this->port($client)->vehicleHasActiveBookings(self::SUPPLIER_ID, 'AA1234BB');
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(BookingQueryUnavailableException::BAD_RESPONSE_CODE, $error->errorCode());
            self::assertStringContainsString('hasActiveBookings', $error->getMessage());
        }
    }

    // --- наскрізний сценарій SUP-06 -----------------------------------------

    /**
     * P-03: щойно створеного постачальника видалити МОЖНА — бронювань у нього
     * бути не може, і сусід це підтверджує.
     */
    public function testFreshSupplierIsDeletableWhenNeighbourReportsNoBookings(): void
    {
        $suppliers = new InMemorySupplierRepository();
        $service = $this->supplierService(
            $suppliers,
            $this->port(new MockHttpClient(new MockResponse($this->body(false)))),
        );

        $supplier = $service->create('ТОВ «Тестовий постачальник»');
        $service->delete($supplier->id());

        // Видалення — soft delete (DATA-03): запис архівується і зникає зі списків.
        self::assertNotNull($service->get($supplier->id())->archivedAt());
        self::assertSame(0, $service->count());
    }

    /** Сусід каже «історія є» — лишається 409 SUPPLIER_HAS_BOOKINGS. */
    public function testSupplierWithHistoryStillCannotBeDeleted(): void
    {
        $suppliers = new InMemorySupplierRepository();
        $service = $this->supplierService(
            $suppliers,
            $this->port(new MockHttpClient(new MockResponse($this->body(true)))),
        );

        $supplier = $service->create('ТОВ «Логістик Плюс»');

        try {
            $service->delete($supplier->id());
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $error) {
            self::assertSame('SUPPLIER_HAS_BOOKINGS', $error->errorCode());
        }

        self::assertTrue($service->get($supplier->id())->isActive());
    }

    /**
     * Сусід мовчить — видалення НЕ відбувається (консервативно), але помилка
     * чесна: 503, а не вигадане «постачальник має бронювання».
     */
    public function testSupplierSurvivesWhenNeighbourIsUnreachable(): void
    {
        $suppliers = new InMemorySupplierRepository();
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });
        $service = $this->supplierService($suppliers, $this->port($client));

        $supplier = $service->create('ТОВ «Логістик Плюс»');

        try {
            $service->delete($supplier->id());
            self::fail('Очікувався BookingQueryUnavailableException.');
        } catch (BookingQueryUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
        }

        self::assertTrue($service->get($supplier->id())->isActive());
    }

    // --- допоміжне ----------------------------------------------------------

    private function port(MockHttpClient $client): HttpBookingQueryPort
    {
        return new HttpBookingQueryPort($client, self::BASE_URL);
    }

    private function supplierService(
        InMemorySupplierRepository $suppliers,
        HttpBookingQueryPort $bookings,
    ): SupplierService {
        return new SupplierService(
            suppliers: $suppliers,
            accounts: new InMemoryPartnerAccountGateway(),
            events: new InMemoryEventPublisher(),
            bookings: $bookings,
            ids: new SequenceIdGenerator('sp'),
            clock: new FixedClock('2026-08-27T09:00:00+00:00'),
        );
    }

    /** Тіло відповіді сусіда за контрактом. */
    private function body(bool $hasAnyBookings): string
    {
        return json_encode(
            ['supplierId' => self::SUPPLIER_ID, 'hasAnyBookings' => $hasAnyBookings],
            \JSON_THROW_ON_ERROR,
        );
    }

    /** Тіло відповіді про авто за контрактом. */
    private function vehicleBody(bool $hasActiveBookings): string
    {
        return json_encode(
            [
                'supplierId' => self::SUPPLIER_ID,
                'plateNumber' => 'AA1234BB',
                'hasActiveBookings' => $hasActiveBookings,
            ],
            \JSON_THROW_ON_ERROR,
        );
    }
}
