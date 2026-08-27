<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\Slot\SlotGridService;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GRID-01: видача сітки слотів магазину на дату.
 * Відповідь містить maxVehicleWeightTons, slotSizeMinutes, leadTimeMinutes
 * і серверний `now` для таймерів на клієнті (GRID-05).
 */
final readonly class SlotGridController
{
    public function __construct(
        private SlotGridService $grid,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    #[Route(
        path: '/api/supplier/v1/stores/{storeId}/slots',
        name: 'supplier_slot_grid',
        methods: ['GET'],
    )]
    public function __invoke(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $date = (string) $request->query->get('date', '');

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationFailedException('Параметр «date» обовʼязковий і має бути у форматі YYYY-MM-DD');
        }

        return new JsonResponse(
            $this->grid->grid($storeId, $date, $actor, $this->clock->now())->toArray(),
        );
    }
}
