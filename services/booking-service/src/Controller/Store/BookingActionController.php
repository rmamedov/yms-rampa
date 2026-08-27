<?php

declare(strict_types=1);

namespace App\Controller\Store;

use App\Application\Booking\BookingLifecycleService;
use App\Domain\Booking\DelayReason;
use App\Domain\Booking\PartialUnload;
use App\Domain\Booking\PartialUnloadReason;
use App\Domain\Booking\RejectionReason;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\BookingPresenter;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Операційні дії магазину над бронюванням: переходи ST-01..ST-07,
 * позначка затримки (DLY-01) і переведення на іншу рампу (EDIT-06).
 */
final readonly class BookingActionController
{
    public function __construct(
        private BookingLifecycleService $lifecycle,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    /** ST-01: booked → arrived. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/arrived', name: 'store_booking_arrived', methods: ['POST'])]
    public function arrived(string $bookingId, Request $request): JsonResponse
    {
        return $this->respond($this->lifecycle->markArrived(
            $this->actors->fromRequest($request),
            $bookingId,
            $this->clock->now(),
        ));
    }

    /** ST-02: arrived → unloading. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/unloading', name: 'store_booking_unloading', methods: ['POST'])]
    public function unloading(string $bookingId, Request $request): JsonResponse
    {
        return $this->respond($this->lifecycle->startUnloading(
            $this->actors->fromRequest($request),
            $bookingId,
            $this->clock->now(),
        ));
    }

    /** ST-03: unloading → completed з фактичною кількістю палет. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/completed', name: 'store_booking_completed', methods: ['POST'])]
    public function completed(string $bookingId, Request $request): JsonResponse
    {
        $payload = RequestPayload::fromRequest($request);
        $partial = null;

        if ($payload->has('partialUnload')) {
            $partialPayload = RequestPayload::fromArray($payload->object('partialUnload'));
            $partial = new PartialUnload(
                reason: self::enumOf(PartialUnloadReason::class, $partialPayload->requiredString('reason')),
                comment: $partialPayload->optionalString('comment'),
            );
        }

        return $this->respond($this->lifecycle->complete(
            actor: $this->actors->fromRequest($request),
            bookingId: $bookingId,
            now: $this->clock->now(),
            unloadedPalletsCount: $payload->optionalInt('unloadedPalletsCount'),
            partialUnload: $partial,
        ));
    }

    /** ST-07: arrived → rejected з причиною з довідника. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/rejected', name: 'store_booking_rejected', methods: ['POST'])]
    public function rejected(string $bookingId, Request $request): JsonResponse
    {
        $payload = RequestPayload::fromRequest($request);

        return $this->respond($this->lifecycle->reject(
            actor: $this->actors->fromRequest($request),
            bookingId: $bookingId,
            reason: self::enumOf(RejectionReason::class, $payload->requiredString('reason')),
            now: $this->clock->now(),
            comment: $payload->optionalString('comment'),
        ));
    }

    /** NOSH-02: ручний no_show після slotEnd. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/no-show', name: 'store_booking_no_show', methods: ['POST'])]
    public function noShow(string $bookingId, Request $request): JsonResponse
    {
        return $this->respond($this->lifecycle->markNoShow(
            $this->actors->fromRequest($request),
            $bookingId,
            $this->clock->now(),
        ));
    }

    /** DLY-01: позначка затримки з причиною та ETA. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/delay', name: 'store_booking_delay', methods: ['POST'])]
    public function delay(string $bookingId, Request $request): JsonResponse
    {
        $payload = RequestPayload::fromRequest($request);

        return $this->respond($this->lifecycle->setDelay(
            actor: $this->actors->fromRequest($request),
            bookingId: $bookingId,
            reason: self::enumOf(DelayReason::class, $payload->requiredString('reason')),
            eta: $payload->requiredDateTime('eta'),
            now: $this->clock->now(),
            comment: $payload->optionalString('comment'),
        ));
    }

    /** EDIT-06: разове переведення на іншу вільну рампу того самого слота. */
    #[Route(path: '/api/store/v1/bookings/{bookingId}/reassign', name: 'store_booking_reassign', methods: ['POST'])]
    public function reassign(string $bookingId, Request $request): JsonResponse
    {
        $payload = RequestPayload::fromRequest($request);

        return $this->respond($this->lifecycle->moveToRamp(
            $this->actors->fromRequest($request),
            $bookingId,
            $payload->requiredString('rampId'),
            $this->clock->now(),
        ));
    }

    private function respond(\App\Domain\Booking\Booking $booking): JsonResponse
    {
        return new JsonResponse(BookingPresenter::toArray($booking));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enumOf(string $enum, string $value): \BackedEnum
    {
        $case = $enum::tryFrom($value);

        if (null === $case) {
            throw new ValidationFailedException(\sprintf(
                'Значення «%s» відсутнє в довіднику. Допустимі: %s',
                $value,
                implode(', ', array_map(static fn (\BackedEnum $c) => (string) $c->value, $enum::cases())),
            ));
        }

        return $case;
    }
}
