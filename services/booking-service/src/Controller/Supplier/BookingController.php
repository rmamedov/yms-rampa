<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\Booking\BookingCreationService;
use App\Application\Booking\BookingLifecycleService;
use App\Application\Booking\NewBookingRequest;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Booking\Exception\BookingNotFoundException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\BookingPresenter;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Кабінет постачальника: створення, перегляд, редагування, перенесення
 * і скасування бронювань (розділи 6.4 і 6.6).
 */
final readonly class BookingController
{
    public function __construct(
        private BookingCreationService $creation,
        private BookingLifecycleService $lifecycle,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    /** BOOK-01..BOOK-09: підтвердження бронювання. */
    #[Route(path: '/api/supplier/v1/bookings', name: 'supplier_booking_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);

        $booking = $this->creation->create($actor, self::newBookingRequest($payload), $this->clock->now());

        return new JsonResponse(BookingPresenter::toArray($booking), Response::HTTP_CREATED);
    }

    #[Route(path: '/api/supplier/v1/bookings/{bookingId}', name: 'supplier_booking_show', methods: ['GET'])]
    public function show(string $bookingId, Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $booking = $this->lifecycle->load($bookingId);

        if (!$actor->role->isNetworkAdmin() && !$actor->belongsToSupplier((string) $booking->supplierId)) {
            throw new BookingNotFoundException($bookingId);
        }

        return new JsonResponse(BookingPresenter::toArray($booking));
    }

    /**
     * EDIT-01/EDIT-04/EDIT-05: зміна слота (перенесення), полів бронювання
     * або водія та авто — залежно від складу тіла запиту.
     */
    #[Route(path: '/api/supplier/v1/bookings/{bookingId}', name: 'supplier_booking_update', methods: ['PATCH'])]
    public function update(string $bookingId, Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);
        $now = $this->clock->now();

        // EDIT-01: наявність нового ключа слота означає перенесення.
        if ($payload->has('slotStart') || $payload->has('rampId')) {
            $booking = $this->creation->reschedule($actor, $bookingId, self::newBookingRequest($payload), $now);

            return new JsonResponse(BookingPresenter::toArray($booking), Response::HTTP_CREATED);
        }

        // EDIT-05: зміна водія та/або авто без зміни слота.
        if ($payload->has('vehicle') || $payload->has('driverId')) {
            $booking = $this->lifecycle->reassign(
                actor: $actor,
                bookingId: $bookingId,
                now: $now,
                driverId: $payload->optionalString('driverId'),
                vehicle: $payload->has('vehicle') ? $payload->vehicle() : null,
                driverProvided: $payload->has('driverId'),
            );

            return new JsonResponse(BookingPresenter::toArray($booking));
        }

        // EDIT-04: orderId і palletsCount.
        $booking = $this->lifecycle->updateDetails(
            actor: $actor,
            bookingId: $bookingId,
            now: $now,
            orderId: $payload->optionalString('orderId'),
            palletsCount: $payload->optionalInt('palletsCount'),
            orderIdProvided: $payload->has('orderId'),
        );

        return new JsonResponse(BookingPresenter::toArray($booking));
    }

    /** ST-04 + EDIT-03: скасування повертає слот у пул. */
    #[Route(path: '/api/supplier/v1/bookings/{bookingId}', name: 'supplier_booking_cancel', methods: ['DELETE'])]
    public function cancel(string $bookingId, Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);

        if (!$actor->role->isSupplier()) {
            throw new AccessDeniedException('Скасування в контурі постачальника доступне лише його користувачам');
        }

        $payload = RequestPayload::fromRequest($request);
        $booking = $this->lifecycle->cancel(
            $actor,
            $bookingId,
            $this->clock->now(),
            $payload->optionalString('reason'),
        );

        return new JsonResponse(BookingPresenter::toArray($booking));
    }

    private static function newBookingRequest(RequestPayload $payload): NewBookingRequest
    {
        return new NewBookingRequest(
            storeId: $payload->requiredString('storeId'),
            rampId: $payload->requiredString('rampId'),
            slotStart: $payload->requiredDateTime('slotStart'),
            vehicle: $payload->vehicle(),
            palletsCount: $payload->requiredInt('palletsCount'),
            orderId: $payload->optionalString('orderId'),
            driverId: $payload->optionalString('driverId'),
            holdToken: $payload->optionalString('holdToken'),
            confirmConflict: $payload->bool('confirmConflict'),
        );
    }
}
