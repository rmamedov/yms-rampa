<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Access\Permission;
use App\Domain\Service\VehicleService;
use App\Domain\Vehicle\Vehicle;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\JsonBody;
use App\Infrastructure\Http\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Довідник машин постачальника «Мої авто» (SUP-VEH-01…SUP-VEH-04).
 *
 * Право за матрицею 4.4 — `vehicle.manage`, надане зі скоупом (S) ролям
 * supplier_admin і supplier_operator.
 *
 * Контур partner: усі операції обмежені постачальником з ідентичності запиту
 * (X-Supplier-Id), тому чуже авто недосяжне ні на читання, ні на зміну;
 * порожній заголовок — 403, а не доступ до всіх постачальників.
 */
#[Route('/api/supplier/v1/vehicles')]
final class SupplierVehicleController
{
    public function __construct(
        private readonly VehicleService $vehicles,
        private readonly ActorResolver $actors,
    ) {
    }

    #[Route('', name: 'supplier_vehicles_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $supplierId = $this->supplierId($request);
        $includeInactive = $request->query->getBoolean('includeInactive');

        $items = $this->vehicles->list($supplierId, $includeInactive);

        return new JsonResponse([
            'items' => array_map(static fn (Vehicle $v): array => View::vehicle($v), $items),
            'total' => \count($items),
        ]);
    }

    /**
     * SUP-BOOK-03: номер нормалізується, дублікат у межах постачальника
     * відхиляється (409 VEHICLE_PLATE_DUPLICATE); вантажопідйомність —
     * 0.5–40 т (DATA-34).
     */
    #[Route('', name: 'supplier_vehicles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $supplierId = $this->supplierId($request);
        $body = JsonBody::fromRequest($request);

        $vehicle = $this->vehicles->create(
            supplierId: $supplierId,
            plateNumber: $body->requiredString('plateNumber'),
            weightTons: $body->requiredFloat('weightTons'),
            brand: $body->optionalString('brand'),
        );

        return new JsonResponse(View::vehicle($vehicle), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'supplier_vehicles_get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::vehicle(
            $this->vehicles->get($this->supplierId($request), $id),
        ));
    }

    #[Route('/{id}', name: 'supplier_vehicles_update', methods: ['PATCH', 'PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $supplierId = $this->supplierId($request);
        $body = JsonBody::fromRequest($request);
        $vehicle = $this->vehicles->get($supplierId, $id);

        if ($body->has('plateNumber')) {
            $vehicle = $this->vehicles->changePlateNumber($supplierId, $id, $body->requiredString('plateNumber'));
        }

        if ($body->has('weightTons')) {
            $vehicle = $this->vehicles->changeWeight($supplierId, $id, $body->requiredFloat('weightTons'));
        }

        if ($body->has('brand')) {
            $vehicle = $this->vehicles->changeBrand($supplierId, $id, $body->optionalString('brand'));
        }

        return new JsonResponse(View::vehicle($vehicle));
    }

    #[Route('/{id}/deactivate', name: 'supplier_vehicles_deactivate', methods: ['POST'])]
    public function deactivate(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::vehicle(
            $this->vehicles->deactivate($this->supplierId($request), $id),
        ));
    }

    #[Route('/{id}/activate', name: 'supplier_vehicles_activate', methods: ['POST'])]
    public function activate(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::vehicle(
            $this->vehicles->activate($this->supplierId($request), $id),
        ));
    }

    /**
     * SUP-VEH-04: якщо авто прив'язане до активних бронювань —
     * 409 VEHICLE_HAS_ACTIVE_BOOKINGS із пропозицією деактивації.
     */
    #[Route('/{id}', name: 'supplier_vehicles_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): Response
    {
        $this->vehicles->delete($this->supplierId($request), $id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Постачальник запиту: ідентичність + право `vehicle.manage` у скоупі.
     */
    private function supplierId(Request $request): string
    {
        return $this->actors->ownSupplierId($request, Permission::VehicleManage);
    }
}
