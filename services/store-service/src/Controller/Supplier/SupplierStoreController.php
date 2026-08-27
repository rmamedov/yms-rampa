<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\Service\SupplierCatalogService;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Каталог магазинів для supplier-web (контур partner).
 *
 * STC-04 / DATA-08: повертаються лише магазини з ymsStatus=active
 * і visibleToSuppliers=true.
 *
 * Ідентичність — єдиний контракт заголовків (див. ActorResolver). Роль
 * постачальника без X-Supplier-Id відхиляється (RBAC-14). Whitelist магазинів
 * (SUP-03) береться з X-Store-Ids і може лише ЗВУЗИТИ вибірку: для партнерського
 * контуру цей заголовок порожній «бо не застосовно», і порожній він НІКОЛИ не
 * означає «нуль магазинів» для постачальника — його скоуп задає supplierId.
 */
#[Route('/api/supplier/v1')]
final class SupplierStoreController extends AbstractController
{
    public function __construct(
        private readonly SupplierCatalogService $catalog,
        private readonly ActorResolver $actors,
    ) {
    }

    #[Route('/cities', methods: ['GET'])]
    public function cities(Request $request): JsonResponse
    {
        $actor = $this->actors->supplier($request);

        return new JsonResponse(['items' => $this->catalog->cities($actor->storeScope())]);
    }

    #[Route('/stores', methods: ['GET'])]
    public function stores(Request $request): JsonResponse
    {
        $actor = $this->actors->supplier($request);

        return new JsonResponse($this->catalog->stores(
            city: $request->query->get('city'),
            allowedStoreIds: $actor->storeScope(),
            page: $request->query->getInt('page', 1),
            perPage: $request->query->getInt('perPage', 100),
        ));
    }

    #[Route('/stores/{storeId}', requirements: ['storeId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function store(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->supplier($request);

        return new JsonResponse($this->catalog->store($storeId, $actor->storeScope()));
    }
}
