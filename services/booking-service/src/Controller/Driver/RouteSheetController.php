<?php

declare(strict_types=1);

namespace App\Controller\Driver;

use App\Application\RouteSheet\RouteSheetService;
use App\Application\Slot\SlotGridService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Role;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RSHT-04: водій бачить лише власні маршрутні листи на поточну дату
 * (та наступні в межах горизонту).
 */
final readonly class RouteSheetController
{
    public function __construct(
        private RouteSheetService $routeSheets,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    #[Route(path: '/api/driver/v1/route-sheet', name: 'driver_route_sheet', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);

        if (Role::Driver !== $actor->role) {
            throw new AccessDeniedException('Маршрутний лист доступний лише водію');
        }

        if (!$actor->hasDriverProfile()) {
            throw AccessDeniedException::driverWithoutProfile();
        }

        $date = (string) $request->query->get('date', SlotGridService::localDate($this->clock->now()));

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationFailedException('Параметр «date» має бути у форматі YYYY-MM-DD');
        }

        // DRV: листи шукаються за ПРОФІЛЕМ водія (X-Driver-Profile-Id) —
        // саме він лежить у booking.driverId, а не обліковий запис із `sub`.
        $driverProfileId = (string) $actor->driverProfileId;

        return new JsonResponse([
            'driverId' => $driverProfileId,
            'date' => $date,
            'routeSheets' => $this->routeSheets->forDriver($driverProfileId, $date),
        ]);
    }
}
