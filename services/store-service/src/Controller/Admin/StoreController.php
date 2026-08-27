<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\StoreAdminService;
use App\Application\Service\StoreCatalogService;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Довідник магазинів для admin-web: список (5.2) і картка (5.3.1).
 * Схема URL контуру staff — /api/admin/v1/... (розділ 2).
 *
 * Скоуп (RBAC-13, RBAC-18): колекції фільтруються мовчки, читання магазину
 * поза скоупом — 404 (існування не розкривається), дія — 403 RBAC_SCOPE_VIOLATION.
 */
#[Route('/api/admin/v1/stores')]
final class StoreController extends AbstractController
{
    private const string UUID = '[0-9a-fA-F-]{36}';

    public function __construct(
        private readonly StoreCatalogService $catalog,
        private readonly StoreAdminService $admin,
        private readonly ActorResolver $actors,
    ) {
    }

    /** STL-01..STL-06: серверні фільтри, пошук, пагінація і сортування. */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);

        return new JsonResponse($this->catalog->list($request->query->all(), $actor->storeScope()));
    }

    /** Довідник міст для фільтра «місто» (STL-02). */
    #[Route('/cities', methods: ['GET'])]
    public function cities(Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);

        return new JsonResponse(['items' => $this->catalog->cities($actor->storeScope())]);
    }

    /** Картка магазину, вкладка «Загальне» (STC-01, STC-02). */
    #[Route('/{storeId}', requirements: ['storeId' => self::UUID], methods: ['GET'])]
    public function card(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanReadStore($storeId);

        return new JsonResponse($this->catalog->card($storeId));
    }

    /** Оновлення YMS-полів; MCP-поля read-only (STC-01, STC-02, STC-03, STC-04, STC-07). */
    #[Route('/{storeId}', requirements: ['storeId' => self::UUID], methods: ['PATCH', 'PUT'])]
    public function update(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanActOnStore($storeId);

        $payload = Payload::fromJson($request->getContent());

        return new JsonResponse($this->admin->updateYmsFields($storeId, $payload));
    }

    /** Масова зміна ymsStatus для вибраних магазинів (UI-02). */
    #[Route('/bulk/status', methods: ['POST'])]
    public function bulkStatus(Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);
        $payload = Payload::fromJson($request->getContent());
        $branchIds = $payload->stringList('branchIds');

        // Масова дія перевіряється до першої зміни: жоден магазин поза скоупом
        // не повинен бути зачеплений навіть частково.
        foreach ($branchIds as $branchId) {
            $actor->assertCanActOnStore($branchId);
        }

        $result = $this->admin->bulkChangeStatus($branchIds, $payload->requireString('ymsStatus'));

        return new JsonResponse($result, [] === $result['failed'] ? Response::HTTP_OK : Response::HTTP_MULTI_STATUS);
    }
}
