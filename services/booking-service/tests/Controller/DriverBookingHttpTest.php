<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Driver\BookingActionController;
use App\Controller\Store\BookingActionController as StoreBookingActionController;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Booking\Booking;
use App\Domain\Exception\ValidationFailedException;
use App\Infrastructure\Http\ActorResolver;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Контур водія (розділ 8, блок DRV):
 *   POST  /api/driver/v1/bookings/{bookingId}/arrived
 *   POST  /api/driver/v1/bookings/{bookingId}/delay
 *   PATCH /api/driver/v1/bookings/{bookingId}
 *
 * Головне правило безпеки: водій діє ВИКЛЮЧНО щодо точок власного
 * маршрутного листа, і жодних інших повноважень контур йому не дає.
 *
 * DRV: належність точки визначає ПРОФІЛЬ водія з X-Driver-Profile-Id,
 * а не обліковий запис із X-User-Id (клейм `sub`) — тут це різні значення,
 * як і на стенді.
 */
#[CoversClass(BookingActionController::class)]
final class DriverBookingHttpTest extends TestCase
{
    /** Профіль водія (partner_users) — те, що лежить у booking.driverId. */
    private const string DRIVER_ID = 'du-1';

    /** Обліковий запис (partner_accounts) — те, що шлюз кладе в X-User-Id. */
    private const string DRIVER_ACCOUNT_ID = 'acc-du-1';

    private const string OTHER_DRIVER_ID = 'du-2';

    // --- Відмітка «На місці» (ST-01) ---------------------------------------

    public function testDriverMarksOwnBookingArrived(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $payload = $this->decode($this->controller($scenario)->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        ));

        self::assertSame('arrived', $payload['status']);
        self::assertSame('2026-08-28T06:52:00Z', $payload['arrivedAt']);
        self::assertSame('arrived', $scenario->reload($booking)->status()->value);
        self::assertCount(1, $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /** Подія BookingArrived має долетіти до магазину — машина стає в чергу. */
    public function testArrivalPublishesEventWithBookingContext(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->controller($scenario)->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        );

        $event = $scenario->outbox->eventsOfType('BookingArrived')[0];

        self::assertSame($booking->id, $event->payload['bookingId']);
        self::assertSame(Scenario::STORE_ID, $event->payload['storeId']);
        self::assertSame('arrived', $event->payload['status']);
    }

    /** Повторне натискання «На місці» не ламає стан і не дублює подію. */
    public function testRepeatedArrivalKeepsStateAndPublishesSingleEvent(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));
        $controller = $this->controller($scenario);

        $first = $this->decode($controller->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        ));

        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:55'));
        $second = $this->decode($controller->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        ));

        self::assertSame('arrived', $second['status']);
        // Момент прибуття лишається первинним, журнал переходів не росте.
        self::assertSame($first['arrivedAt'], $second['arrivedAt']);
        self::assertCount(2, $second['statusHistory']);
        self::assertCount(1, $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /** «Хто перший»: магазин уже відмітив — кнопка водія не повертає помилку. */
    public function testArrivalAfterStoreMarkedItIsNotAnError(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:50'));

        $store = new StoreBookingActionController($scenario->lifecycle, new ActorResolver(), $scenario->clock);
        $store->arrived($booking->id, $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/arrived'));

        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:53'));
        $payload = $this->decode($this->controller($scenario)->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        ));

        self::assertSame('arrived', $payload['status']);
        self::assertSame('2026-08-28T06:50:00Z', $payload['arrivedAt']);
        self::assertCount(1, $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /**
     * DRV, головна регресія стенду: обліковий запис у клеймі `sub`
     * (X-User-Id) НЕ дорівнює профілю водія в booking.driverId. Кнопка
     * «На місці» має спрацювати саме за профілем — раніше тут був 403
     * у кожного водія.
     */
    public function testArrivalWorksWhenAccountIdDiffersFromDriverProfileId(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        self::assertSame(self::DRIVER_ID, $booking->driverId());
        self::assertNotSame(self::DRIVER_ID, self::DRIVER_ACCOUNT_ID);

        $payload = $this->decode($this->controller($scenario)->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        ));

        self::assertSame('arrived', $payload['status']);
    }

    /** Усі три дії контуру доступні водієві з ЙОГО профілем. */
    #[DataProvider('driverEndpoints')]
    public function testEveryDriverEndpointAcceptsOwnProfile(string $endpoint): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $response = $this->call(
            $this->controller($scenario),
            $endpoint,
            $booking->id,
            $this->requestFor($endpoint, $booking->id),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Чужі бронювання: 403 ----------------------------------------------

    /**
     * Чужий профіль у заголовку — 403: заголовок не «підтверджує» доступ,
     * бронювання все одно належить іншому профілю.
     */
    #[DataProvider('driverEndpoints')]
    public function testDriverWithForeignProfileIdIsDenied(string $endpoint): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->call(
            $this->controller($scenario),
            $endpoint,
            $booking->id,
            self::requestWithIdentity($endpoint, self::DRIVER_ACCOUNT_ID, 'driver', $booking->id, self::OTHER_DRIVER_ID),
        );
    }

    /**
     * DRV: водій без привʼязаного профілю не діє взагалі — 403 на всіх
     * трьох маршрутах, навіть на бронюванні, driverId якого збігся з його
     * обліковим записом (запасного порівняння з `sub` немає).
     */
    #[DataProvider('driverEndpointsWithoutProfile')]
    public function testEveryDriverEndpointRejectsDriverWithoutProfile(string $endpoint, ?string $header): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ACCOUNT_ID);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Обліковий запис водія не привʼязаний до профілю водія');

        $this->call(
            $this->controller($scenario),
            $endpoint,
            $booking->id,
            self::requestWithIdentity($endpoint, self::DRIVER_ACCOUNT_ID, 'driver', $booking->id, $header),
        );
    }

    /** Відмова водієві без профілю не має побічних ефектів. */
    public function testBookingStaysUntouchedWhenDriverHasNoProfile(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ACCOUNT_ID);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        try {
            $this->controller($scenario)->arrived(
                $booking->id,
                $this->driverRequest(
                    'POST',
                    '/api/driver/v1/bookings/'.$booking->id.'/arrived',
                    driverProfileId: null,
                ),
            );
            self::fail('Очікувався AccessDeniedException');
        } catch (AccessDeniedException $error) {
            self::assertSame(403, $error->httpStatus());
            self::assertSame('ACCESS_DENIED', $error->errorCode());
        }

        self::assertSame('booked', $scenario->reload($booking)->status()->value);
        self::assertSame([], $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /**
     * Заголовок профілю не робить водієм нікого: не-водій із коректним
     * X-Driver-Profile-Id усе одно впирається в перевірку ролі.
     */
    #[DataProvider('nonDriverActorsWithDriverProfile')]
    public function testDriverProfileHeaderGrantsNothingToOtherRoles(string $endpoint, Request $request): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Контур водія доступний лише користувачам з роллю «driver»');

        $this->call($this->controller($scenario), $endpoint, $booking->id, $request);
    }

    /** Водій А не відмічає прибуття бронювання, призначеного водієві Б. */
    public function testDriverCannotMarkArrivedOnAnotherDriversBooking(): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book('2026-08-28 10:00', driverId: self::OTHER_DRIVER_ID);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->controller($scenario)->arrived(
            $foreign->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$foreign->id.'/arrived'),
        );
    }

    /** Бронювання іншого постачальника недосяжне для водія так само. */
    public function testDriverCannotMarkArrivedOnAnotherSuppliersBooking(): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book(
            '2026-08-28 10:00',
            rampId: 'r2',
            supplierId: Scenario::OTHER_SUPPLIER_ID,
            driverId: self::OTHER_DRIVER_ID,
        );
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);

        $this->controller($scenario)->arrived(
            $foreign->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$foreign->id.'/arrived'),
        );
    }

    /** Бронювання без призначеного водія не належить нікому — теж 403. */
    public function testDriverCannotMarkArrivedOnBookingWithoutDriver(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);

        $this->controller($scenario)->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        );
    }

    /** Чуже бронювання не змінює стану: 403 не має побічних ефектів. */
    public function testForeignBookingStaysUntouchedAfterDenial(): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book('2026-08-28 10:00', driverId: self::OTHER_DRIVER_ID);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        try {
            $this->controller($scenario)->arrived(
                $foreign->id,
                $this->driverRequest('POST', '/api/driver/v1/bookings/'.$foreign->id.'/arrived'),
            );
            self::fail('Очікувався AccessDeniedException');
        } catch (AccessDeniedException $error) {
            self::assertSame(403, $error->httpStatus());
            self::assertSame('ACCESS_DENIED', $error->errorCode());
        }

        self::assertSame('booked', $scenario->reload($foreign)->status()->value);
        self::assertSame([], $scenario->outbox->eventsOfType('BookingArrived'));
    }

    /** 403 на всіх трьох маршрутах контуру, а не лише на «arrived». */
    #[DataProvider('driverEndpoints')]
    public function testEveryDriverEndpointRejectsForeignBooking(string $endpoint): void
    {
        $scenario = new Scenario();
        $foreign = $scenario->book('2026-08-28 10:00', driverId: self::OTHER_DRIVER_ID);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Бронювання не входить до маршрутного листа цього водія');

        $this->call($this->controller($scenario), $endpoint, $foreign->id, $this->requestFor($endpoint, $foreign->id));
    }

    /** Контур водія закритий для магазину, постачальника й адміністратора. */
    #[DataProvider('nonDriverActors')]
    public function testDriverContourRejectsOtherRoles(string $endpoint, Request $request): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Контур водія доступний лише користувачам з роллю «driver»');

        $this->call($this->controller($scenario), $endpoint, $booking->id, $request);
    }

    // --- Затримка (DLY-01) --------------------------------------------------

    public function testDriverReportsDelayWithReasonAndEta(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $payload = $this->decode($this->controller($scenario)->delay(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/delay', [
                'reason' => 'затори',
                'eta' => '2026-08-28T08:30:00Z',
            ]),
        ));

        self::assertTrue($payload['delayed']['flag']);
        self::assertSame('затори', $payload['delayed']['reason']);
        self::assertSame('2026-08-28T08:30:00Z', $payload['delayed']['eta']);
        // Статус не змінюється — затримка це лише прапорець.
        self::assertSame('booked', $payload['status']);
        self::assertCount(1, $scenario->outbox->eventsOfType('BookingDelaySet'));
    }

    /** Причина «інше» потребує коментаря — правило домену DLY-01. */
    public function testDelayWithOtherReasonRequiresComment(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->delay(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/delay', [
                'reason' => 'інше',
                'eta' => '2026-08-28T08:30:00Z',
            ]),
        );
    }

    public function testDelayEtaMustBeInFuture(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->delay(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/delay', [
                'reason' => 'поломка',
                'eta' => '2026-08-28T05:00:00Z',
            ]),
        );
    }

    public function testDelayReasonMustComeFromDictionary(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:30'));

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->delay(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/delay', [
                'reason' => 'проспав',
                'eta' => '2026-08-28T08:30:00Z',
            ]),
        );
    }

    // --- PATCH orderId ------------------------------------------------------

    public function testDriverAddsOrderIdToOwnBooking(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);

        self::assertNull($booking->orderId());

        $payload = $this->decode($this->controller($scenario)->update(
            $booking->id,
            $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id, ['orderId' => '4410233']),
        ));

        self::assertSame('4410233', $payload['orderId']);
        self::assertSame('4410233', $scenario->reload($booking)->orderId());
    }

    /** Після відмітки прибуття номер ще можна дописати. */
    public function testDriverAddsOrderIdAfterArrival(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $controller = $this->controller($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $controller->arrived(
            $booking->id,
            $this->driverRequest('POST', '/api/driver/v1/bookings/'.$booking->id.'/arrived'),
        );

        $payload = $this->decode($controller->update(
            $booking->id,
            $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id, ['orderId' => '4410999']),
        ));

        self::assertSame('arrived', $payload['status']);
        self::assertSame('4410999', $payload['orderId']);
    }

    /** Після початку розвантаження номер уже не редагується. */
    public function testDriverCannotChangeOrderIdAfterUnloadingStarted(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);
        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:52'));

        $store = new StoreBookingActionController($scenario->lifecycle, new ActorResolver(), $scenario->clock);
        $store->arrived($booking->id, $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/arrived'));
        $store->unloading($booking->id, $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/unloading'));

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->update(
            $booking->id,
            $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id, ['orderId' => '4410999']),
        );
    }

    /** PATCH водія змінює ТІЛЬКИ orderId: будь-яке інше поле — 403. */
    #[DataProvider('forbiddenPatchFields')]
    public function testDriverPatchRejectsAnyFieldExceptOrderId(array $body): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Водій може змінювати лише «orderId»');

        $this->controller($scenario)->update(
            $booking->id,
            $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id, $body),
        );
    }

    /** Заборонене поле не проходить навіть у парі з дозволеним. */
    public function testDriverPatchRejectsMixedBodyWithoutApplyingOrderId(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);

        try {
            $this->controller($scenario)->update(
                $booking->id,
                $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id, [
                    'orderId' => '4410233',
                    'palletsCount' => 33,
                ]),
            );
            self::fail('Очікувався AccessDeniedException');
        } catch (AccessDeniedException) {
        }

        $reloaded = $scenario->reload($booking);

        self::assertNull($reloaded->orderId());
        self::assertSame(8, $reloaded->palletsCount());
    }

    public function testDriverPatchRequiresOrderIdField(): void
    {
        $scenario = new Scenario();
        $booking = $this->ownBooking($scenario);

        $this->expectException(ValidationFailedException::class);
        $this->controller($scenario)->update(
            $booking->id,
            $this->driverRequest('PATCH', '/api/driver/v1/bookings/'.$booking->id),
        );
    }

    // --- Дані для провайдерів ----------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function driverEndpoints(): iterable
    {
        yield 'arrived' => ['arrived'];
        yield 'delay' => ['delay'];
        yield 'update' => ['update'];
    }

    /**
     * Порожній X-Driver-Profile-Id на кожному з трьох маршрутів контуру.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function driverEndpointsWithoutProfile(): iterable
    {
        $headers = [
            'заголовка немає' => null,
            'порожній рядок' => '',
            'самі пробіли' => '   ',
        ];

        foreach (['arrived', 'delay', 'update'] as $endpoint) {
            foreach ($headers as $label => $header) {
                yield $endpoint.' → '.$label => [$endpoint, $header];
            }
        }
    }

    /**
     * @return iterable<string, array{string, Request}>
     */
    public static function nonDriverActors(): iterable
    {
        foreach (self::nonDriverRoles() as $label => [$userId, $role]) {
            foreach (['arrived', 'delay', 'update'] as $endpoint) {
                $request = self::requestWithIdentity($endpoint, $userId, $role);

                yield $label.' → '.$endpoint => [$endpoint, $request];
            }
        }
    }

    /**
     * Ті самі ролі, але з підставленим чужим X-Driver-Profile-Id.
     *
     * @return iterable<string, array{string, Request}>
     */
    public static function nonDriverActorsWithDriverProfile(): iterable
    {
        foreach (self::nonDriverRoles() as $label => [$userId, $role]) {
            foreach (['arrived', 'delay', 'update'] as $endpoint) {
                $request = self::requestWithIdentity($endpoint, $userId, $role, 'bk-1', self::DRIVER_ID);

                yield $label.' → '.$endpoint => [$endpoint, $request];
            }
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    private static function nonDriverRoles(): array
    {
        return [
            'магазин' => ['su-1', 'store_manager'],
            'постачальник' => ['pu-sp-1', 'supplier_admin'],
            'адміністратор мережі' => ['ad-1', 'network_manager'],
        ];
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function forbiddenPatchFields(): iterable
    {
        yield 'palletsCount' => [['palletsCount' => 20]];
        yield 'driverId' => [['driverId' => self::OTHER_DRIVER_ID]];
        yield 'vehicle' => [['vehicle' => ['plateNumber' => 'AA0000AA', 'weightTons' => 3]]];
        yield 'slotStart' => [['slotStart' => '2026-08-28T09:00:00Z']];
        yield 'rampId' => [['rampId' => 'r2']];
        yield 'status' => [['status' => 'completed']];
    }

    // --- Допоміжне ----------------------------------------------------------

    private function controller(Scenario $scenario): BookingActionController
    {
        return new BookingActionController($scenario->driverBookings, new ActorResolver(), $scenario->clock);
    }

    /** Бронювання, призначене водієві du-1 — точка ЙОГО маршрутного листа. */
    private function ownBooking(Scenario $scenario): Booking
    {
        return $scenario->book('2026-08-28 10:00', driverId: self::DRIVER_ID);
    }

    private function call(
        BookingActionController $controller,
        string $endpoint,
        string $bookingId,
        Request $request,
    ): JsonResponse {
        return match ($endpoint) {
            'arrived' => $controller->arrived($bookingId, $request),
            'delay' => $controller->delay($bookingId, $request),
            'update' => $controller->update($bookingId, $request),
        };
    }

    private function requestFor(string $endpoint, string $bookingId): Request
    {
        return self::requestWithIdentity(
            $endpoint,
            self::DRIVER_ACCOUNT_ID,
            'driver',
            $bookingId,
            self::DRIVER_ID,
        );
    }

    private static function requestWithIdentity(
        string $endpoint,
        string $userId,
        string $role,
        string $bookingId = 'bk-1',
        ?string $driverProfileId = null,
    ): Request {
        [$method, $uri, $body] = match ($endpoint) {
            'arrived' => ['POST', '/api/driver/v1/bookings/'.$bookingId.'/arrived', []],
            'delay' => ['POST', '/api/driver/v1/bookings/'.$bookingId.'/delay', [
                'reason' => 'затори',
                'eta' => '2026-08-28T08:30:00Z',
            ]],
            'update' => ['PATCH', '/api/driver/v1/bookings/'.$bookingId, ['orderId' => '4410233']],
        };

        $request = Request::create($uri, $method, content: [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set(ActorResolver::USER_HEADER, $userId);
        $request->headers->set(ActorResolver::ROLE_HEADER, $role);

        if ('supplier_admin' === $role) {
            $request->headers->set(ActorResolver::SUPPLIER_HEADER, Scenario::SUPPLIER_ID);
        }

        if ('store_manager' === $role) {
            $request->headers->set(ActorResolver::STORES_HEADER, Scenario::STORE_ID);
        }

        if (null !== $driverProfileId) {
            $request->headers->set(ActorResolver::DRIVER_PROFILE_HEADER, $driverProfileId);
        }

        return $request;
    }

    /**
     * Запит водія: обліковий запис у X-User-Id, ПРОФІЛЬ у X-Driver-Profile-Id.
     * Ці два ідентифікатори свідомо різні — саме їхнє змішування ламало кнопку
     * «На місці» на стенді.
     *
     * @param array<string, mixed> $body
     */
    private function driverRequest(
        string $method,
        string $uri,
        array $body = [],
        ?string $driverProfileId = self::DRIVER_ID,
        string $userId = self::DRIVER_ACCOUNT_ID,
    ): Request {
        $request = Request::create($uri, $method, content: [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set(ActorResolver::USER_HEADER, $userId);
        $request->headers->set(ActorResolver::ROLE_HEADER, 'driver');

        if (null !== $driverProfileId) {
            $request->headers->set(ActorResolver::DRIVER_PROFILE_HEADER, $driverProfileId);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function storeRequest(string $method, string $uri, array $body = []): Request
    {
        $request = Request::create($uri, $method, content: [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'store_manager');
        $request->headers->set(ActorResolver::STORES_HEADER, Scenario::STORE_ID);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
