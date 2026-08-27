<?php

declare(strict_types=1);

namespace App\Controller\Store;

use App\Application\Booking\BookingCreationService;
use App\Application\Booking\WalkInRequest;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\BookingPresenter;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * WALK-01: реєстрація позапланового прибуття магазином.
 * Бронювання створюється одразу в статусі `arrived` (WALK-04).
 */
final readonly class WalkInController
{
    public function __construct(
        private BookingCreationService $creation,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/api/store/v1/bookings/walk-in', name: 'store_booking_walk_in', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);

        $booking = $this->creation->registerWalkIn($actor, new WalkInRequest(
            storeId: $payload->requiredString('storeId'),
            rampId: $payload->requiredString('rampId'),
            slotStart: $payload->requiredDateTime('slotStart'),
            vehicle: $payload->vehicle(),
            palletsCount: $payload->requiredInt('palletsCount'),
            supplierId: $payload->optionalString('supplierId'),
            supplierName: $payload->optionalString('supplierName'),
            orderId: $payload->optionalString('orderId'),
        ), $this->clock->now());

        return new JsonResponse(BookingPresenter::toArray($booking), Response::HTTP_CREATED);
    }
}
