<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Store\StoreReadService;
use App\Controller\Store\StoreReadController;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Role;
use App\Domain\Booking\DelayReason;
use App\Domain\Driver\DriverInfo;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Store\StoreNotFoundException;
use App\Domain\Supplier\SupplierInfo;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\ProblemExceptionListener;
use App\Infrastructure\Http\ProblemResponseFactory;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Контур ЧИТАННЯ магазину: /api/store/v1/stores…, /api/store/v1/bookings.
 *
 * Перевіряються три речі: форма відповіді (її розбирає store-web без
 * додаткового мапінгу), розбір параметрів і — окремим блоком — права.
 */
#[CoversClass(StoreReadController::class)]
#[CoversClass(StoreReadService::class)]
final class StoreReadHttpTest extends TestCase
{
    private const string OTHER_STORE_ID = 'store-2';

    // --- Перелік магазинів -------------------------------------------------

    /** Магазинна роль бачить рівно свій скоуп, а не всю мережу. */
    public function testStoreListReturnsOnlyScopedStores(): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $payload = $this->decode($this->controller($scenario)->stores(
            $this->request('GET', '/api/store/v1/stores'),
        ));

        self::assertCount(1, $payload);
        self::assertSame(Scenario::STORE_ID, $payload[0]['storeId']);
    }

    /** Форма елемента — рівно те, чим підписані перемикач філії і шапка. */
    public function testStoreListItemCarriesSwitcherFields(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode($this->controller($scenario)->stores(
            $this->request('GET', '/api/store/v1/stores'),
        ));

        self::assertSame(
            ['storeId', 'externalId', 'displayName', 'city', 'address', 'ymsStatus'],
            array_keys($payload[0]),
        );
        self::assertSame('1998', $payload[0]['externalId']);
        self::assertSame('Сільпо Хрещатик', $payload[0]['displayName']);
        self::assertSame('Київ', $payload[0]['city']);
        self::assertSame('active', $payload[0]['ymsStatus']);
    }

    /** RBAC-16: мережева роль бачить усі філії, хоча X-Store-Ids порожній. */
    public function testStoreListReturnsWholeNetworkForNetworkRoles(): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $payload = $this->decode($this->controller($scenario)->stores(
            $this->request('GET', '/api/store/v1/stores', role: 'network_manager', stores: ''),
        ));

        self::assertCount(2, $payload);
        self::assertSame(
            [Scenario::STORE_ID, self::OTHER_STORE_ID],
            array_column($payload, 'storeId'),
        );
    }

    /** RBAC-13: порожній скоуп магазинної ролі — нуль магазинів, а не мережа. */
    public function testStoreListIsEmptyForStoreRoleWithoutScope(): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $payload = $this->decode($this->controller($scenario)->stores(
            $this->request('GET', '/api/store/v1/stores', stores: ''),
        ));

        self::assertSame([], $payload);
    }

    // --- Конфігурація філії ------------------------------------------------

    public function testStoreConfigMatchesFrontendContract(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode($this->controller($scenario)->config(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/config'),
        ));

        self::assertSame(Scenario::STORE_ID, $payload['storeId']);
        self::assertSame('1998', $payload['externalId']);
        self::assertSame('вул. Хрещатик, 12', $payload['address']);
        self::assertSame(30, $payload['slotSizeMinutes']);
        self::assertEqualsWithDelta(20.0, $payload['maxVehicleWeightTons'], 0.001);
        self::assertSame(60, $payload['leadTimeMinutes']);
        self::assertSame(14, $payload['horizonDays']);
        // Grace no-show живе в політиці магазину, а не в геометрії сітки.
        self::assertSame(30, $payload['noShowGraceMinutes']);
    }

    public function testStoreConfigCarriesRampsAndReceivingWindows(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode($this->controller($scenario)->config(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/config'),
        ));

        self::assertCount(2, $payload['ramps']);
        self::assertSame(['rampId' => 'r1', 'name' => 'Рампа 1', 'active' => true], $payload['ramps'][0]);

        // Вікна прийому: пн–сб, по одному інтервалу 08:00–14:00.
        self::assertCount(6, $payload['receivingWindows']);
        self::assertSame(1, $payload['receivingWindows'][0]['dayOfWeek']);
        self::assertSame(
            [['from' => '08:00', 'to' => '14:00']],
            $payload['receivingWindows'][0]['intervals'],
        );
    }

    /** Магазину немає — 404 STORE_NOT_FOUND, а не порожня конфігурація. */
    public function testStoreConfigOfUnknownStoreIsNotFound(): void
    {
        $scenario = new Scenario();

        $this->expectException(StoreNotFoundException::class);
        $this->controller($scenario)->config(
            self::OTHER_STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-2/config', role: 'super_admin', stores: ''),
        );
    }

    // --- Довідник постачальників -------------------------------------------

    public function testSupplierDirectoryReturnsRefsForWalkInForm(): void
    {
        $scenario = new Scenario();

        $payload = $this->decode($this->controller($scenario)->suppliers(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/suppliers'),
        ));

        self::assertCount(2, $payload);
        self::assertSame(['supplierId', 'name'], array_keys($payload[0]));
        self::assertSame(
            ['ТОВ Молокія', 'ТОВ Хлібзавод'],
            array_column($payload, 'name'),
        );
    }

    /** SUP-02/SUP-03: неактивний і чужий постачальник у довідник не потрапляє. */
    public function testSupplierDirectoryHidesSuspendedAndForeignSuppliers(): void
    {
        $scenario = new Scenario();
        $scenario->suppliers->add(new SupplierInfo('sp-3', 'ТОВ Призупинений', active: false));
        $scenario->suppliers->add(new SupplierInfo('sp-4', 'ТОВ Інша філія', allowedStoreIds: [self::OTHER_STORE_ID]));

        $payload = $this->decode($this->controller($scenario)->suppliers(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/suppliers'),
        ));

        self::assertSame(['sp-1', 'sp-2'], array_column($payload, 'supplierId'));
    }

    // --- Дошка прибуттів ---------------------------------------------------

    public function testBoardReturnsBookingsOfTheDayWithServerNow(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');
        $scenario->book('2026-08-28 11:00', rampId: 'r2');
        // Наступна доба на дошку 28-го не потрапляє.
        $scenario->book('2026-08-29 09:00');

        $payload = $this->decode($this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=2026-08-28'),
        ));

        self::assertSame(Scenario::STORE_ID, $payload['storeId']);
        self::assertSame('2026-08-28', $payload['date']);
        self::assertSame('2026-08-27T06:00:00Z', $payload['now']);
        self::assertCount(2, $payload['bookings']);
        self::assertSame(['10:00', '11:00'], array_column($payload['bookings'], 'localTime'));
    }

    /** Картка прибуття: усе, що на ній намальовано, приходить одним запитом. */
    public function testBoardBookingCarriesEveryCardField(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: 'du-7');
        $scenario->driverProfiles->add(new DriverInfo('du-7', 'Іваненко Іван', '+380671234567'));

        $card = $this->decode($this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=2026-08-28'),
        ))['bookings'][0];

        self::assertSame($booking->id, $card['id']);
        self::assertSame('scheduled', $card['type']);
        self::assertSame('booked', $card['status']);
        self::assertSame('r1', $card['rampId']);
        self::assertSame('ТОВ Молокія', $card['supplierName']);
        self::assertSame('AA1234BB', $card['vehicle']['plateNumber']);
        self::assertEqualsWithDelta(5.0, $card['vehicle']['weightTons'], 0.001);
        self::assertSame(8, $card['palletsCount']);
        self::assertArrayHasKey('orderId', $card);
        self::assertNull($card['arrivedAt']);
        self::assertNull($card['unloadingStartedAt']);
        self::assertNull($card['completedAt']);
        self::assertFalse($card['delayed']['flag']);

        // Водій: ідентифікатор лишається, поруч зʼявляється людина.
        self::assertSame('du-7', $card['driverId']);
        self::assertSame('Іваненко Іван', $card['driver']['fullName']);
        self::assertSame('+380671234567', $card['driver']['phone']);
    }

    /** Затримка приходить із причиною та ETA (DLY-01). */
    public function testBoardShowsDelayFlagWithReasonAndEta(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->lifecycle->setDelay(
            $scenario->storeStaff(),
            $booking->id,
            DelayReason::TrafficJam,
            Scenario::kyiv('2026-08-28 11:00'),
            $scenario->now(),
        );

        $card = $this->decode($this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=2026-08-28'),
        ))['bookings'][0];

        self::assertTrue($card['delayed']['flag']);
        self::assertSame('затори', $card['delayed']['reason']);
        self::assertSame('2026-08-28T08:00:00Z', $card['delayed']['eta']);
    }

    /** Позапланове прибуття на дошці відрізняється типом (WALK-01). */
    public function testBoardIncludesWalkInBookings(): void
    {
        $scenario = new Scenario();
        $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );

        $payload = $this->decode($this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=2026-08-27'),
        ));

        self::assertCount(1, $payload['bookings']);
        self::assertSame('walk_in', $payload['bookings'][0]['type']);
        self::assertSame('arrived', $payload['bookings'][0]['status']);
    }

    /** Невідомий водій не ламає дошку — картка лишається без імені. */
    public function testBoardKeepsCardWhenDriverProfileIsUnknown(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00', driverId: 'du-zniklyi');

        $card = $this->decode($this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=2026-08-28'),
        ))['bookings'][0];

        self::assertSame('du-zniklyi', $card['driverId']);
        self::assertNull($card['driver']);
    }

    public function testBoardRequiresStoreIdAndDate(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->board($this->request('GET', '/api/store/v1/bookings?date=2026-08-28'));
    }

    public function testBoardRejectsMalformedDate(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->board(
            $this->request('GET', '/api/store/v1/bookings?storeId=store-1&date=28.08.2026'),
        );
    }

    // --- Сітка слотів -------------------------------------------------------

    public function testSlotGridReturnsFlatArrayWithBookingIds(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $slots = $this->decode($this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots?date=2026-08-28'),
        ));

        // Дві рампи × 12 слотів вікна 08:00–14:00.
        self::assertCount(24, $slots);

        $booked = array_values(array_filter($slots, static fn (array $s) => 'booked' === $s['state']));
        self::assertCount(1, $booked);
        self::assertSame($booking->id, $booked[0]['bookingId']);
        self::assertSame('r1', $booked[0]['rampId']);
        self::assertSame('2026-08-28T07:00:00Z', $booked[0]['slotStart']);

        // Вільний слот несе bookingId = null, а не відсутнє поле.
        $free = array_values(array_filter($slots, static fn (array $s) => 'available' === $s['state']));
        self::assertNull($free[0]['bookingId']);
    }

    /** Staff-контур: чужі резерви видно (GRID-04 ховає їх від постачальника). */
    public function testSlotGridShowsForeignReservationsToStaff(): void
    {
        $scenario = new Scenario();
        $scenario->overlays->addReservedRule(Scenario::STORE_ID, new ReservedSlotRule(
            supplierId: Scenario::OTHER_SUPPLIER_ID,
            rampId: 'r1',
            slotStartTime: '09:00',
            dayOfWeek: 5,
        ));

        $slots = $this->decode($this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots?date=2026-08-28'),
        ));

        $reserved = array_values(array_filter($slots, static fn (array $s) => 'reserved' === $s['state']));

        self::assertNotSame([], $reserved);
        self::assertSame(Scenario::OTHER_SUPPLIER_ID, $reserved[0]['reservedForSupplierId']);
    }

    public function testSlotGridRequiresDate(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots'),
        );
    }

    // --- Тиждень ------------------------------------------------------------

    public function testWeekGridReturnsSevenDaysKeyedByLocalDate(): void
    {
        $scenario = new Scenario();

        $week = $this->decode($this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots?from=2026-08-24&days=7'),
        ));

        self::assertCount(7, $week);
        self::assertSame(
            ['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28', '2026-08-29', '2026-08-30'],
            array_column($week, 'dateKey'),
        );
        // Неділя поза вікном прийому — доба є, слотів немає.
        self::assertCount(24, $week[0]['slots']);
        self::assertSame([], $week[6]['slots']);
    }

    public function testWeekGridDefaultsToSevenDays(): void
    {
        $scenario = new Scenario();

        $week = $this->decode($this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots?from=2026-08-24'),
        ));

        self::assertCount(7, $week);
    }

    public function testWeekGridRejectsUnreasonableRange(): void
    {
        $scenario = new Scenario();

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->slots(
            Scenario::STORE_ID,
            $this->request('GET', '/api/store/v1/stores/store-1/slots?from=2026-08-24&days=90'),
        );
    }

    // --- Права --------------------------------------------------------------

    /**
     * RBAC-13: магазинна роль не читає чужу філію. Перевіряються ВСІ маршрути
     * контуру — дірка в одному з них рівноцінна дірці в усіх.
     */
    #[DataProvider('storeScopedRoutes')]
    public function testForeignStoreIsForbiddenForStoreRoles(string $route): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $this->expectException(AccessDeniedException::class);
        $this->call($scenario, $route, self::OTHER_STORE_ID, 'store_manager', Scenario::STORE_ID);
    }

    /** Те саме для приймальника: роль нижча, правило те саме. */
    #[DataProvider('storeScopedRoutes')]
    public function testForeignStoreIsForbiddenForStoreOperator(string $route): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $this->expectException(AccessDeniedException::class);
        $this->call($scenario, $route, self::OTHER_STORE_ID, 'store_operator', Scenario::STORE_ID);
    }

    /** RBAC-13: порожній скоуп = нуль доступу, а не «всі магазини». */
    #[DataProvider('storeScopedRoutes')]
    public function testEmptyScopeGrantsNothingToStoreRoles(string $route): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $this->call($scenario, $route, Scenario::STORE_ID, 'store_manager', '');
    }

    /**
     * RBAC-16: мережеві ролі працюють у будь-якій філії з порожнім X-Store-Ids —
     * вимога канонічної матриці прав (booking.read.all = ✓ для super_admin
     * і network_manager, PermissionMatrix identity-staff-service).
     */
    #[DataProvider('networkRolesAndRoutes')]
    public function testNetworkRolesReadAnyStore(string $role, string $route): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $response = $this->call($scenario, $route, self::OTHER_STORE_ID, $role, '');

        self::assertSame(200, $response->getStatusCode());
    }

    /** Контур магазину закритий для водія і для ролей постачальника. */
    #[DataProvider('partnerRolesAndRoutes')]
    public function testPartnerContourRolesAreForbidden(string $role, string $route): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $this->call($scenario, $route, Scenario::STORE_ID, $role, Scenario::STORE_ID);
    }

    /** Перелік магазинів теж закритий для партнерського контуру. */
    #[DataProvider('partnerRoles')]
    public function testStoreListIsForbiddenForPartnerContour(string $role): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $this->controller($scenario)->stores($this->request(
            'GET',
            '/api/store/v1/stores',
            role: $role,
            stores: Scenario::STORE_ID,
        ));
    }

    /** Аналітик читає мережу в контурі аналітики, а не в контурі магазину. */
    public function testAnalystIsOutsideStoreContour(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $this->controller($scenario)->stores(
            $this->request('GET', '/api/store/v1/stores', role: 'analyst', stores: ''),
        );
    }

    /** Запит без заголовків ідентичності не обслуговується взагалі. */
    public function testRequestWithoutIdentityHeadersIsDenied(): void
    {
        $scenario = new Scenario();

        $this->expectException(AccessDeniedException::class);
        $this->controller($scenario)->stores(Request::create('/api/store/v1/stores', 'GET'));
    }

    /**
     * Відмова доходить до клієнта як RFC 7807 з полем `code`, а не як 500:
     * без цього store-web показав би «щось пішло не так» замість «немає прав».
     */
    public function testForbiddenReadIsRenderedAsProblemJson(): void
    {
        $scenario = new Scenario();
        $scenario->registerStore(self::OTHER_STORE_ID);

        $response = $this->render(
            fn () => $this->call($scenario, 'board', self::OTHER_STORE_ID, 'store_manager', Scenario::STORE_ID),
            '/api/store/v1/bookings',
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);

        self::assertSame('ACCESS_DENIED', $payload['code']);
        self::assertSame(403, $payload['status']);
        self::assertStringContainsString(self::OTHER_STORE_ID, $payload['detail']);
    }

    /** Некоректна дата — 422 VALIDATION_FAILED, а не 403 і не 500. */
    public function testMalformedDateIsRenderedAsValidationProblem(): void
    {
        $scenario = new Scenario();

        $response = $this->render(
            fn () => $this->controller($scenario)->slots(
                Scenario::STORE_ID,
                $this->request('GET', '/api/store/v1/stores/store-1/slots?date=28-08-2026'),
            ),
            '/api/store/v1/stores/store-1/slots',
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'VALIDATION_FAILED',
            json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR)['code'],
        );
    }

    /** Проганяє виняток контролера через слухач помилок HTTP-шару. */
    private function render(callable $action, string $uri): Response
    {
        try {
            $action();
            self::fail('Очікувався виняток контуру магазину.');
        } catch (\Throwable $error) {
            $kernel = new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            };

            $event = new ExceptionEvent(
                $kernel,
                Request::create($uri, 'GET'),
                HttpKernelInterface::MAIN_REQUEST,
                $error,
            );

            (new ProblemExceptionListener(new ProblemResponseFactory()))($event);

            $response = $event->getResponse();
            self::assertNotNull($response);

            return $response;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storeScopedRoutes(): iterable
    {
        yield 'config' => ['config'];
        yield 'suppliers' => ['suppliers'];
        yield 'board' => ['board'];
        yield 'slots' => ['slots'];
        yield 'week' => ['week'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function networkRolesAndRoutes(): iterable
    {
        foreach (['super_admin', 'network_manager'] as $role) {
            foreach (self::storeScopedRoutes() as $case) {
                yield $role.' → '.$case[0] => [$role, $case[0]];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function partnerRolesAndRoutes(): iterable
    {
        foreach (self::partnerRoles() as $case) {
            foreach (self::storeScopedRoutes() as $route) {
                yield $case[0].' → '.$route[0] => [$case[0], $route[0]];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function partnerRoles(): iterable
    {
        yield 'driver' => ['driver'];
        yield 'supplier_admin' => ['supplier_admin'];
        yield 'supplier_operator' => ['supplier_operator'];
    }

    // --- інфраструктура тесту ----------------------------------------------

    private function call(
        Scenario $scenario,
        string $route,
        string $storeId,
        string $role,
        string $stores,
    ): JsonResponse {
        $controller = $this->controller($scenario);
        $request = $this->request('GET', $this->uriOf($route, $storeId), role: $role, stores: $stores);

        return match ($route) {
            'config' => $controller->config($storeId, $request),
            'suppliers' => $controller->suppliers($storeId, $request),
            'board' => $controller->board($request),
            'slots', 'week' => $controller->slots($storeId, $request),
            default => throw new \LogicException('Невідомий маршрут '.$route),
        };
    }

    private function uriOf(string $route, string $storeId): string
    {
        return match ($route) {
            'config' => \sprintf('/api/store/v1/stores/%s/config', $storeId),
            'suppliers' => \sprintf('/api/store/v1/stores/%s/suppliers', $storeId),
            'board' => \sprintf('/api/store/v1/bookings?storeId=%s&date=2026-08-28', $storeId),
            'slots' => \sprintf('/api/store/v1/stores/%s/slots?date=2026-08-28', $storeId),
            'week' => \sprintf('/api/store/v1/stores/%s/slots?from=2026-08-24&days=7', $storeId),
            default => throw new \LogicException('Невідомий маршрут '.$route),
        };
    }

    private function controller(Scenario $scenario): StoreReadController
    {
        return new StoreReadController($scenario->storeRead, new ActorResolver(), $scenario->clock);
    }

    private function request(
        string $method,
        string $uri,
        string $role = 'store_manager',
        string $stores = Scenario::STORE_ID,
    ): Request {
        $request = Request::create($uri, $method);
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, $role);
        $request->headers->set(ActorResolver::STORES_HEADER, $stores);

        if (Role::from($role)->isSupplier()) {
            $request->headers->set(ActorResolver::SUPPLIER_HEADER, Scenario::SUPPLIER_ID);
        }

        if (Role::Driver === Role::from($role)) {
            $request->headers->set(ActorResolver::DRIVER_PROFILE_HEADER, 'du-1');
        }

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
