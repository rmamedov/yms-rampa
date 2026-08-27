<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Driver\RouteSheetController as DriverRouteSheetController;
use App\Controller\Store\BookingActionController;
use App\Controller\Store\WalkInController;
use App\Controller\Supplier\BookingController;
use App\Controller\Supplier\SlotGridController;
use App\Controller\Supplier\SlotHoldController;
use App\Domain\Exception\ValidationFailedException;
use App\Infrastructure\Http\ActorResolver;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP-контур: схема URL /api/{admin|store|supplier|driver}/v1/...,
 * розбір тіла запиту та представлення відповіді.
 */
#[CoversClass(BookingController::class)]
final class BookingHttpTest extends TestCase
{
    /** GET /api/supplier/v1/stores/{storeId}/slots */
    public function testSlotGridEndpointReturnsGrid(): void
    {
        $scenario = new Scenario();
        $controller = new SlotGridController($scenario->grid, new ActorResolver(), $scenario->clock);

        $payload = $this->decode($controller(
            Scenario::STORE_ID,
            $this->supplierRequest('GET', '/api/supplier/v1/stores/store-1/slots?date=2026-08-28'),
        ));

        self::assertSame('2026-08-28', $payload['date']);
        self::assertCount(24, $payload['slots']);
        self::assertSame(30, $payload['slotSizeMinutes']);
    }

    public function testSlotGridRequiresDateParameter(): void
    {
        $scenario = new Scenario();
        $controller = new SlotGridController($scenario->grid, new ActorResolver(), $scenario->clock);

        $this->expectException(ValidationFailedException::class);
        $controller(Scenario::STORE_ID, $this->supplierRequest('GET', '/api/supplier/v1/stores/store-1/slots'));
    }

    /** POST /api/supplier/v1/bookings */
    public function testCreateBookingEndpointReturns201(): void
    {
        $scenario = new Scenario();
        $controller = $this->bookingController($scenario);

        $response = $controller->create($this->supplierRequest('POST', '/api/supplier/v1/bookings', [
            'storeId' => Scenario::STORE_ID,
            'rampId' => 'r1',
            'slotStart' => '2026-08-28T07:00:00Z',
            'vehicle' => ['plateNumber' => 'aa1234bb', 'weightTons' => 5, 'brand' => 'MAN'],
            'palletsCount' => 8,
            'orderId' => 'ORD-55871',
        ]));

        $payload = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('booked', $payload['status']);
        self::assertSame('scheduled', $payload['type']);
        self::assertSame('AA1234BB', $payload['vehicle']['plateNumber']);
        self::assertSame('10:00', $payload['localTime']);
    }

    public function testCreateBookingRequiresPalletsCount(): void
    {
        $scenario = new Scenario();
        $controller = $this->bookingController($scenario);

        $this->expectException(ValidationFailedException::class);
        $controller->create($this->supplierRequest('POST', '/api/supplier/v1/bookings', [
            'storeId' => Scenario::STORE_ID,
            'rampId' => 'r1',
            'slotStart' => '2026-08-28T07:00:00Z',
            'vehicle' => ['plateNumber' => 'AA1234BB', 'weightTons' => 5],
        ]));
    }

    /** DELETE /api/supplier/v1/bookings/{id} */
    public function testCancelBookingEndpoint(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book();
        $controller = $this->bookingController($scenario);

        $payload = $this->decode($controller->cancel(
            $booking->id,
            $this->supplierRequest('DELETE', '/api/supplier/v1/bookings/'.$booking->id, ['reason' => 'змінилися плани']),
        ));

        self::assertSame('cancelled', $payload['status']);
        self::assertSame('supplier', $payload['cancellation']['by']);
    }

    /** PATCH /api/supplier/v1/bookings/{id} з новим ключем слота = перенесення. */
    public function testPatchWithNewSlotPerformsReschedule(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $controller = $this->bookingController($scenario);

        $response = $controller->update($booking->id, $this->supplierRequest('PATCH', '/api/supplier/v1/bookings/'.$booking->id, [
            'storeId' => Scenario::STORE_ID,
            'rampId' => 'r1',
            'slotStart' => '2026-08-28T09:00:00Z',
            'vehicle' => ['plateNumber' => 'AA1234BB', 'weightTons' => 5],
            'palletsCount' => 8,
        ]));

        $payload = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($booking->id, $payload['rescheduleOf']);
        self::assertSame('12:00', $payload['localTime']);
    }

    /** POST /api/store/v1/bookings/walk-in */
    public function testWalkInEndpointCreatesArrivedBooking(): void
    {
        $scenario = new Scenario();
        $controller = new WalkInController($scenario->creation, new ActorResolver(), $scenario->clock);

        $response = $controller($this->storeRequest('POST', '/api/store/v1/bookings/walk-in', [
            'storeId' => Scenario::STORE_ID,
            'rampId' => 'r1',
            'slotStart' => '2026-08-27T06:00:00Z',
            'vehicle' => ['plateNumber' => 'BC5555CT', 'weightTons' => 3.5],
            'palletsCount' => 4,
            'supplierName' => 'ФОП Іваненко',
        ]));

        $payload = $this->decode($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('walk_in', $payload['type']);
        self::assertSame('arrived', $payload['status']);
        self::assertSame('ФОП Іваненко', $payload['supplierName']);
    }

    /** POST /api/store/v1/bookings/{id}/arrived|unloading|completed */
    public function testStoreActionEndpointsDriveLifecycle(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $controller = new BookingActionController($scenario->lifecycle, new ActorResolver(), $scenario->clock);

        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:58'));
        self::assertSame('arrived', $this->decode($controller->arrived(
            $booking->id,
            $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/arrived'),
        ))['status']);

        $scenario->clock->set(Scenario::kyiv('2026-08-28 10:02'));
        self::assertSame('unloading', $this->decode($controller->unloading(
            $booking->id,
            $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/unloading'),
        ))['status']);

        $scenario->clock->set(Scenario::kyiv('2026-08-28 10:25'));
        $completed = $this->decode($controller->completed(
            $booking->id,
            $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/completed', [
                'unloadedPalletsCount' => 6,
                'partialUnload' => ['reason' => 'немає місця'],
            ]),
        ));

        self::assertSame('completed', $completed['status']);
        self::assertSame(6, $completed['unloadedPalletsCount']);
        self::assertSame('немає місця', $completed['partialUnload']['reason']);
    }

    /** ST-07: причина відмови має бути з довідника. */
    public function testRejectEndpointValidatesReasonAgainstDictionary(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->creation->registerWalkIn(
            $scenario->storeStaff(),
            $scenario->walkInRequest('2026-08-27 09:00'),
            $scenario->now(),
        );
        $controller = new BookingActionController($scenario->lifecycle, new ActorResolver(), $scenario->clock);

        $this->expectException(ValidationFailedException::class);
        $controller->rejected(
            $booking->id,
            $this->storeRequest('POST', '/api/store/v1/bookings/'.$booking->id.'/rejected', ['reason' => 'не сподобався водій']),
        );
    }

    /** POST /api/supplier/v1/slots/hold і DELETE .../hold */
    public function testHoldEndpointsCreateAndRelease(): void
    {
        $scenario = new Scenario();
        $controller = new SlotHoldController($scenario->holdService, new ActorResolver(), $scenario->clock);
        $body = [
            'storeId' => Scenario::STORE_ID,
            'rampId' => 'r1',
            'slotStart' => '2026-08-28T07:00:00Z',
        ];

        $created = $controller->create($this->supplierRequest('POST', '/api/supplier/v1/slots/hold', $body));
        $hold = $this->decode($created);

        self::assertSame(201, $created->getStatusCode());
        self::assertSame(300, $hold['secondsLeft']);

        $released = $controller->release($this->supplierRequest(
            'DELETE',
            '/api/supplier/v1/slots/hold',
            array_merge($body, ['holdToken' => $hold['holdToken']]),
        ));

        self::assertSame(204, $released->getStatusCode());
        self::assertNull($scenario->holds->get($scenario->slotKey(), $scenario->now()));
    }

    /** GET /api/driver/v1/route-sheet */
    public function testDriverRouteSheetEndpoint(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');
        $scenario->routeSheets->assignDriverToBooking($scenario->supplier(), $booking->id, 'du-7', $scenario->now());

        $controller = new DriverRouteSheetController($scenario->routeSheets, new ActorResolver(), $scenario->clock);

        $request = Request::create('/api/driver/v1/route-sheet?date=2026-08-28', 'GET');
        $request->headers->set(ActorResolver::USER_HEADER, 'du-7');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'driver');

        $payload = $this->decode($controller($request));

        self::assertSame('du-7', $payload['driverId']);
        self::assertCount(1, $payload['routeSheets']);
        self::assertSame($booking->id, $payload['routeSheets'][0]['points'][0]['bookingId']);
    }

    private function bookingController(Scenario $scenario): BookingController
    {
        return new BookingController(
            $scenario->creation,
            $scenario->lifecycle,
            new ActorResolver(),
            $scenario->clock,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function supplierRequest(string $method, string $uri, array $body = []): Request
    {
        $request = Request::create($uri, $method, content: [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
        $request->headers->set(ActorResolver::USER_HEADER, 'pu-sp-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'supplier_admin');
        $request->headers->set(ActorResolver::SUPPLIER_HEADER, Scenario::SUPPLIER_ID);

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
