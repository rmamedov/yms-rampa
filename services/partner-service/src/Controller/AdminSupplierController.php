<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Service\SupplierService;
use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierContact;
use App\Domain\Supplier\SupplierStatus;
use App\Infrastructure\Http\JsonBody;
use App\Infrastructure\Http\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Адмін-API постачальників (розділ 5.4: SUP-01, SUP-02, SUP-03, SUP-06).
 *
 * Контур staff: доступ мають ролі super_admin і network_manager —
 * перевірку ролі виконує api-gateway (ADM-01, ADM-02).
 */
#[Route('/api/admin/v1/suppliers')]
final class AdminSupplierController
{
    public function __construct(private readonly SupplierService $suppliers)
    {
    }

    #[Route('', name: 'admin_suppliers_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $request->query->get('q');
        $statusRaw = $request->query->get('status');
        $status = \is_string($statusRaw) && '' !== $statusRaw ? SupplierStatus::fromInput($statusRaw) : null;
        $limit = max(1, min(200, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset'));

        $items = $this->suppliers->search(
            \is_string($query) ? $query : null,
            $status,
            $limit,
            $offset,
        );

        return new JsonResponse([
            'items' => array_map(static fn (Supplier $s): array => View::supplier($s), $items),
            'total' => $this->suppliers->count(\is_string($query) ? $query : null, $status),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    #[Route('', name: 'admin_suppliers_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = JsonBody::fromRequest($request);

        $supplier = $this->suppliers->create(
            name: $body->requiredString('name'),
            edrpou: $body->optionalString('edrpou'),
            storeAccess: self::storeAccess($body),
            contacts: self::contacts($body),
        );

        return new JsonResponse(View::supplier($supplier), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'admin_suppliers_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        return new JsonResponse(View::supplier($this->suppliers->get($id)));
    }

    /**
     * Часткове оновлення: застосовуються лише передані поля.
     */
    #[Route('/{id}', name: 'admin_suppliers_update', methods: ['PATCH', 'PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $body = JsonBody::fromRequest($request);
        $supplier = $this->suppliers->get($id);

        if ($body->has('name')) {
            $supplier = $this->suppliers->rename($id, $body->requiredString('name'));
        }

        if ($body->has('edrpou')) {
            $supplier = $this->suppliers->changeEdrpou($id, $body->optionalString('edrpou'));
        }

        if ($body->has('allStores') || $body->has('storeIds')) {
            $supplier = $this->suppliers->changeStoreAccess($id, self::storeAccess($body) ?? StoreAccess::allStores());
        }

        if ($body->has('contacts')) {
            $supplier = $this->suppliers->replaceContacts($id, self::contacts($body));
        }

        return new JsonResponse(View::supplier($supplier));
    }

    /**
     * SUP-02: призупинення постачальника — логіни блокуються,
     * публікується канонічна подія SupplierSuspended.
     */
    #[Route('/{id}/suspend', name: 'admin_suppliers_suspend', methods: ['POST'])]
    public function suspend(string $id, Request $request): JsonResponse
    {
        $body = JsonBody::fromRequest($request);

        return new JsonResponse(View::supplier(
            $this->suppliers->suspend($id, $body->optionalString('reason')),
        ));
    }

    #[Route('/{id}/activate', name: 'admin_suppliers_activate', methods: ['POST'])]
    public function activate(string $id): JsonResponse
    {
        return new JsonResponse(View::supplier($this->suppliers->activate($id)));
    }

    /**
     * SUP-06: видалення можливе лише за відсутності бронювань будь-якого
     * статусу, інакше — 409 SUPPLIER_HAS_BOOKINGS.
     */
    #[Route('/{id}', name: 'admin_suppliers_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->suppliers->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * SUP-03: «всі магазини» або whitelist філій.
     */
    private static function storeAccess(JsonBody $body): ?StoreAccess
    {
        $allStores = $body->optionalBool('allStores');
        $storeIds = $body->optionalList('storeIds');

        if (null === $allStores && null === $storeIds) {
            return null;
        }

        // Whitelist — якщо явно вимкнено «всі магазини» або передано перелік філій.
        if (false === $allStores || (null === $allStores && null !== $storeIds)) {
            return StoreAccess::whitelist(array_map(
                static fn (mixed $id): string => (string) $id,
                $storeIds ?? [],
            ));
        }

        return StoreAccess::allStores();
    }

    /**
     * @return list<SupplierContact>
     */
    private static function contacts(JsonBody $body): array
    {
        $raw = $body->optionalList('contacts') ?? [];
        $contacts = [];

        foreach ($raw as $item) {
            if (\is_array($item)) {
                /** @var array<string, mixed> $item */
                $contacts[] = SupplierContact::fromArray($item);
            }
        }

        return $contacts;
    }
}
