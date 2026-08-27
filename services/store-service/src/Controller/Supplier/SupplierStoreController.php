<?php

declare(strict_types=1);

namespace App\Controller\Supplier;

use App\Application\Service\SupplierCatalogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Каталог магазинів для supplier-web (контур partner).
 *
 * STC-04 / DATA-08: повертаються лише магазини з ymsStatus=active
 * і visibleToSuppliers=true. Whitelist магазинів постачальника (SUP-03)
 * передається api-gateway у заголовку X-Supplier-Stores.
 */
#[Route('/api/supplier/v1')]
final class SupplierStoreController extends AbstractController
{
    public function __construct(
        private readonly SupplierCatalogService $catalog,
    ) {
    }

    #[Route('/cities', methods: ['GET'])]
    public function cities(): JsonResponse
    {
        return new JsonResponse(['items' => $this->catalog->cities()]);
    }

    #[Route('/stores', methods: ['GET'])]
    public function stores(Request $request): JsonResponse
    {
        return new JsonResponse($this->catalog->stores(
            city: $request->query->get('city'),
            allowedStoreIds: self::allowedStoreIds($request),
            page: $request->query->getInt('page', 1),
            perPage: $request->query->getInt('perPage', 100),
        ));
    }

    #[Route('/stores/{storeId}', requirements: ['storeId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function store(string $storeId, Request $request): JsonResponse
    {
        return new JsonResponse($this->catalog->store($storeId, self::allowedStoreIds($request)));
    }

    /**
     * SUP-03: режим «всі магазини» (заголовок відсутній) або whitelist філій.
     *
     * @return list<string>|null
     */
    private static function allowedStoreIds(Request $request): ?array
    {
        $header = $request->headers->get('X-Supplier-Stores');

        if (null === $header || '' === trim($header)) {
            return null;
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $header)),
            static fn (string $id): bool => '' !== $id,
        ));
    }
}
