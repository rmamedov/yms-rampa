<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use App\Application\Booking\DriverBookingService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\Booking;
use App\Domain\Booking\DelayReason;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\BookingPresenter;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Дії водія над точками власного маршрутного листа (розділ 8, блок DRV):
 * відмітка «На місці», повідомлення про затримку і дописування orderId.
 *
 * Ідентичність приходить зі службових заголовків шлюзу (X-User-Id,
 * X-User-Role, X-Supplier-Id, X-Store-Ids, X-Driver-Profile-Id, X-Contour)
 * — розбирає їх ActorResolver, власного розбору тут немає.
 *
 * Контур закритий потрійно: спершу роль має бути саме `driver`, далі водій
 * має мати привʼязаний профіль (X-Driver-Profile-Id), і вже потім
 * DriverBookingService перевіряє, що бронювання належить маршрутному листу
 * цього ПРОФІЛЮ. Чуже бронювання — 403 ACCESS_DENIED.
 */
final readonly class BookingActionController
{
    /**
     * Закритий перелік полів, які водій має право змінювати через PATCH.
     * Усе інше (palletsCount, vehicle, driverId, slotStart, rampId, status)
     * — повноваження постачальника або магазину.
     *
     * @var list<string>
     */
    private const array EDITABLE_FIELDS = ['orderId'];

    public function __construct(
        private DriverBookingService $driverBookings,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    /** DRV + ST-01: «На місці» — booked → arrived, подія BookingArrived. */
    #[Route(
        path: '/api/driver/v1/bookings/{bookingId}/arrived',
        name: 'driver_booking_arrived',
        methods: ['POST'],
    )]
    public function arrived(string $bookingId, Request $request): JsonResponse
    {
        return $this->respond($this->driverBookings->markArrived(
            $this->driver($request),
            $bookingId,
            $this->clock->now(),
        ));
    }

    /** DRV + DLY-01: повідомлення про затримку — причина з довідника та новий ETA. */
    #[Route(
        path: '/api/driver/v1/bookings/{bookingId}/delay',
        name: 'driver_booking_delay',
        methods: ['POST'],
    )]
    public function delay(string $bookingId, Request $request): JsonResponse
    {
        $payload = RequestPayload::fromRequest($request);

        return $this->respond($this->driverBookings->reportDelay(
            actor: $this->driver($request),
            bookingId: $bookingId,
            reason: $payload->requiredEnum(DelayReason::class, 'reason'),
            eta: $payload->requiredDateTime('eta'),
            now: $this->clock->now(),
            comment: $payload->optionalString('comment'),
        ));
    }

    /** DRV: водій дописує номер замовлення, якщо його не вказав постачальник. */
    #[Route(
        path: '/api/driver/v1/bookings/{bookingId}',
        name: 'driver_booking_update',
        methods: ['PATCH'],
    )]
    public function update(string $bookingId, Request $request): JsonResponse
    {
        $actor = $this->driver($request);
        $payload = RequestPayload::fromRequest($request);
        $forbidden = array_values(array_diff($payload->keys(), self::EDITABLE_FIELDS));

        if ([] !== $forbidden) {
            // Назви полів приходять від клієнта, тому в повідомлення потрапляє
            // лише коротка обрізана вибірка — воно має пояснювати, а не бути
            // дзеркалом довільного тіла запиту.
            $listed = array_map(
                static fn (string $field) => mb_substr($field, 0, 40),
                \array_slice($forbidden, 0, 5),
            );

            throw new AccessDeniedException(\sprintf(
                'Водій може змінювати лише «orderId»; недоступні поля: %s',
                implode(', ', $listed),
            ));
        }

        if (!$payload->has('orderId')) {
            throw new ValidationFailedException('Поле «orderId» обовʼязкове');
        }

        return $this->respond($this->driverBookings->updateOrderId(
            $actor,
            $bookingId,
            $payload->optionalString('orderId'),
            $this->clock->now(),
        ));
    }

    /**
     * Актор контуру водія. Роль звіряється до будь-якого доступу до сховища:
     * магазин, постачальник і адміністратор мережі мають власні контури
     * і сюди не заходять.
     *
     * DRV: роль `driver` без привʼязаного профілю (порожній
     * X-Driver-Profile-Id) не діє в контурі взагалі — порівнювати з
     * booking.driverId нічого, а обліковий запис із `sub` для цього
     * не годиться.
     */
    private function driver(Request $request): Actor
    {
        $actor = $this->actors->fromRequest($request);

        if (Role::Driver !== $actor->role) {
            throw AccessDeniedException::driverContourOnly();
        }

        if (!$actor->hasDriverProfile()) {
            throw AccessDeniedException::driverWithoutProfile();
        }

        return $actor;
    }

    private function respond(Booking $booking): JsonResponse
    {
        return new JsonResponse(BookingPresenter::toArray($booking));
    }
}
