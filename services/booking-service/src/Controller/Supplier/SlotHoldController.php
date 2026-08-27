<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\Hold\SlotHoldService;
use App\Domain\Shared\Clock;
use App\Domain\Slot\SlotKey;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Холди слота (HOLD-01..HOLD-03): створення при відкритті форми,
 * продовження при активності, зняття при закритті.
 */
final readonly class SlotHoldController
{
    public function __construct(
        private SlotHoldService $holds,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/api/supplier/v1/slots/hold', name: 'supplier_slot_hold_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);
        $hold = $this->holds->hold($actor, self::slotKey($payload), $this->clock->now());

        return new JsonResponse(
            array_merge($hold->toArray(), ['secondsLeft' => $hold->secondsLeft($this->clock->now())]),
            Response::HTTP_CREATED,
        );
    }

    /** HOLD-02: heartbeat раз на 60 с або значуща дія у формі. */
    #[Route(path: '/api/supplier/v1/slots/hold', name: 'supplier_slot_hold_extend', methods: ['PATCH'])]
    public function extend(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);

        $hold = $this->holds->extend(
            $actor,
            self::slotKey($payload),
            $payload->requiredString('holdToken'),
            $this->clock->now(),
        );

        return new JsonResponse(
            array_merge($hold->toArray(), ['secondsLeft' => $hold->secondsLeft($this->clock->now())]),
        );
    }

    #[Route(path: '/api/supplier/v1/slots/hold', name: 'supplier_slot_hold_release', methods: ['DELETE'])]
    public function release(Request $request): JsonResponse
    {
        $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);

        $this->holds->release(self::slotKey($payload), $payload->requiredString('holdToken'));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private static function slotKey(RequestPayload $payload): SlotKey
    {
        return new SlotKey(
            $payload->requiredString('storeId'),
            $payload->requiredString('rampId'),
            $payload->requiredDateTime('slotStart'),
        );
    }
}
