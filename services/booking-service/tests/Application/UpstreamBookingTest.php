<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Booking\BookingCreationService;
use App\Application\Booking\NewBookingRequest;
use App\Application\RouteSheet\RouteSheetService;
use App\Application\Slot\SlotGridService;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Exception\SlotReservedException;
use App\Domain\Booking\Exception\SupplierNotAllowedException;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Domain\Slot\SlotGridGenerator;
use App\Infrastructure\InMemory\InMemoryBookingRepository;
use App\Infrastructure\InMemory\InMemoryOutboxStore;
use App\Infrastructure\InMemory\InMemoryRouteSheetRepository;
use App\Infrastructure\InMemory\InMemorySlotHoldStore;
use App\Infrastructure\InMemory\SequentialIdGenerator;
use App\Infrastructure\Store\HttpSlotOverlayProvider;
use App\Infrastructure\Store\HttpStoreConfigProvider;
use App\Infrastructure\Store\HttpStoreServiceClient;
use App\Infrastructure\Supplier\HttpSupplierDirectory;
use App\Tests\Support\Scenario;
use App\Tests\Support\StoreSettingsPayload;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Бронювання, зібране на РЕАЛЬНИХ адаптерах сусідів: конфігурація магазину і
 * накладання приходять зі store-service, стан постачальника — з partner-service.
 * Сховища лишаються в памʼяті, мережі немає — обидва транспорти підмінені
 * MockHttpClient.
 *
 * Саме цей шлях на живому стенді падав з 403 SUPPLIER_NOT_ALLOWED (порожній
 * InMemory-довідник) і будував сітку з фікстури замість налаштувань філії.
 */
#[CoversClass(HttpSupplierDirectory::class)]
#[CoversClass(HttpStoreConfigProvider::class)]
#[CoversClass(HttpSlotOverlayProvider::class)]
final class UpstreamBookingTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';
    private const string SUPPLIER_ID = 'sp-9';

    /** Понеділок 08:00 за Києвом — вікно прийому фікстури відкрите. */
    private const string NOW = '2026-08-31T05:00:00Z';

    public function testBookingUsesRealStoreAndSupplierData(): void
    {
        $creation = $this->creation();

        $booking = $creation->create(
            $this->actor(),
            $this->request('2026-08-31 11:00'),
            new DateTimeImmutable(self::NOW),
        );

        // Снапшот філії — з тіла store-service, а не з локальної фікстури.
        self::assertSame('Сільпо на Хрещатику', $booking->storeSnapshot->displayName);
        self::assertSame('00123', $booking->storeSnapshot->externalId);
        // Назва постачальника — з partner-service.
        self::assertSame('ТОВ «Молочна ріка»', $booking->supplierNameSnapshot);
        // Слот 30 хв — з конфігурації магазину.
        self::assertSame(1800, $booking->slotEnd->getTimestamp() - $booking->slotStart->getTimestamp());
    }

    /** BOOK-02: призупинений постачальник отримує 403, а не створює бронювання. */
    public function testSuspendedSupplierCannotBook(): void
    {
        $creation = $this->creation(supplierAccess: [
            'status' => 'suspended',
            'allowed' => false,
            'reason' => 'SUPPLIER_SUSPENDED',
        ]);

        $this->expectException(SupplierNotAllowedException::class);
        $creation->create($this->actor(), $this->request('2026-08-31 11:00'), new DateTimeImmutable(self::NOW));
    }

    /**
     * BOOK-05 / GRID-04: резерв ІНШОГО постачальника приїхав зі store-service
     * і реально закриває слот — вівторок 09:00 на рампі r1 віддано sp-1.
     */
    public function testReservedSlotOfAnotherSupplierIsClosed(): void
    {
        $creation = $this->creation();

        $this->expectException(SlotReservedException::class);
        $creation->create($this->actor(), $this->request('2026-09-08 09:00'), new DateTimeImmutable(self::NOW));
    }

    /** Недоступний store-service не перетворюється на 500 всередині бронювання. */
    public function testStoreServiceOutageSurfacesAsDomainProblem(): void
    {
        $storeClient = new HttpStoreServiceClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((static function () {
                yield new TransportException('Connection refused for "http://127.0.0.1:8081".');
            })())),
            self::BASE_URL,
        );

        $creation = $this->creation(storeClient: $storeClient);

        try {
            $creation->create($this->actor(), $this->request('2026-08-31 11:00'), new DateTimeImmutable(self::NOW));
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertSame('store-service', $error->service);
        }
    }

    /**
     * @param array<string, mixed> $supplierAccess
     */
    private function creation(
        array $supplierAccess = [],
        ?HttpStoreServiceClient $storeClient = null,
    ): BookingCreationService {
        $storeClient ??= new HttpStoreServiceClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse(StoreSettingsPayload::json())),
            self::BASE_URL,
        );

        $partner = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(array_replace([
                'supplierId' => self::SUPPLIER_ID,
                'name' => 'ТОВ «Молочна ріка»',
                'status' => 'active',
                'allStores' => true,
                'allowedStoreIds' => [],
                'storeId' => StoreSettingsPayload::STORE_ID,
                'allowed' => true,
                'reason' => null,
            ], $supplierAccess), \JSON_THROW_ON_ERROR)
        ));

        $outbox = new InMemoryOutboxStore();
        $bookings = new InMemoryBookingRepository($outbox);
        $holds = new InMemorySlotHoldStore();

        $grid = new SlotGridService(
            new HttpStoreConfigProvider($storeClient),
            new HttpSlotOverlayProvider($storeClient),
            $bookings,
            $holds,
            new SlotGridGenerator(),
        );

        return new BookingCreationService(
            $grid,
            $bookings,
            $holds,
            new HttpSupplierDirectory($partner, self::BASE_URL),
            new RouteSheetService(
                new InMemoryRouteSheetRepository(),
                $bookings,
                new SequentialIdGenerator('rs-'),
                new HttpStoreConfigProvider($storeClient),
            ),
            new SequentialIdGenerator('bk-'),
        );
    }

    private function actor(): Actor
    {
        return new Actor('pu-9', Role::SupplierAdmin, supplierId: self::SUPPLIER_ID);
    }

    private function request(string $localDateTime, string $rampId = 'r1'): NewBookingRequest
    {
        return new NewBookingRequest(
            storeId: StoreSettingsPayload::STORE_ID,
            rampId: $rampId,
            slotStart: Scenario::kyiv($localDateTime),
            vehicle: Scenario::vehicle(weightTons: 8.0),
            palletsCount: 6,
        );
    }
}
