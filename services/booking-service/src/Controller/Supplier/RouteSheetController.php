<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\RouteSheet\RouteSheetService;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Shared\Clock;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\RequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Маршрутні листи постачальника (RSHT-02, RSHT-03).
 */
final readonly class RouteSheetController
{
    public function __construct(
        private RouteSheetService $routeSheets,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    /** RSHT-03: дані друкованої версії листа на дату. */
    #[Route(path: '/api/supplier/v1/route-sheets', name: 'supplier_route_sheet', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $date = self::date($request);
        $supplierId = (string) ($request->query->get('supplierId') ?? $actor->supplierId);

        if ('' === $supplierId) {
            throw new AccessDeniedException('Не визначено постачальника маршрутного листа');
        }

        return new JsonResponse($this->routeSheets->printView($actor, $supplierId, $date));
    }

    /** RSHT-02: призначення водія на весь лист або на окреме бронювання. */
    #[Route(path: '/api/supplier/v1/route-sheets/driver', name: 'supplier_route_sheet_driver', methods: ['POST'])]
    public function assignDriver(Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $payload = RequestPayload::fromRequest($request);
        $now = $this->clock->now();

        if ($payload->has('bookingId')) {
            $sheet = $this->routeSheets->assignDriverToBooking(
                $actor,
                $payload->requiredString('bookingId'),
                $payload->optionalString('driverId'),
                $now,
            );
        } else {
            // Поле має БУТИ в тілі, але може дорівнювати null або порожньому
            // рядку — це «зняти водія з усього листа» (RSHT-02). Різниця
            // важлива: відсутнє поле — майже завжди помилка клієнта, і мовчки
            // знімати водія за неї не можна.
            if (!$payload->has('driverId')) {
                throw new ValidationFailedException('Поле «driverId» обовʼязкове');
            }

            $sheet = $this->routeSheets->assignDriverToSheet(
                $actor,
                $payload->optionalString('supplierId') ?? (string) $actor->supplierId,
                $payload->requiredString('date'),
                $payload->optionalString('driverId'),
                $now,
            );
        }

        return new JsonResponse($sheet->toArray());
    }

    private static function date(Request $request): string
    {
        $date = (string) $request->query->get('date', '');

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationFailedException('Параметр «date» обовʼязковий і має бути у форматі YYYY-MM-DD');
        }

        return $date;
    }
}
