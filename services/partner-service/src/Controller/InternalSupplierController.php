<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Service\SupplierService;
use App\Domain\Supplier\SupplierAccessSnapshot;
use App\Infrastructure\Http\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службовий API постачальників для booking-service (BOOK-02).
 *
 * КОНТРАКТ:
 *
 *   GET /internal/v1/suppliers/{supplierId}
 *   200 {"supplierId","name","status":"active|suspended","allStores":bool,
 *        "allowedStoreIds":[…]}  — перелік порожній, коли allStores=true;
 *   404 application/problem+json, `code` = SUPPLIER_NOT_FOUND.
 *
 *   GET /internal/v1/suppliers/{supplierId}/store-access/{storeId}
 *   200 те саме + {"storeId","allowed":bool,"reason":null|"SUPPLIER_SUSPENDED"
 *        |"SUPPLIER_STORE_NOT_ALLOWED"};
 *   404 так само SUPPLIER_NOT_FOUND.
 *
 * Другий маршрут — не цукор: бронювання перевіряє саме зв'язку «постачальник +
 * магазин», і рішення приймається тут, за одне звернення, без ризику, що
 * споживач по-своєму витлумачить порожній allowedStoreIds або статус.
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: маршрути обслуговує лише внутрішній
 * шлюз nginx на 127.0.0.1:8081 (map `$yms_internal_service`), назовні вони
 * недосяжні. Через auth_request такі запити не проходять і заголовків
 * ідентичності не мають, тому ActorResolver тут свідомо НЕ викликається —
 * інакше кожен міжсервісний виклик отримував би 403 ACCESS_DENIED.
 */
#[Route('/internal/v1/suppliers')]
final readonly class InternalSupplierController
{
    public function __construct(private SupplierService $suppliers)
    {
    }

    /**
     * Довідник постачальників для форми позапланового прибуття магазину.
     *
     *   GET /internal/v1/suppliers?storeId={id}&limit={n}&offset={n}
     *   200 {"items":[…supplierAccess…],"total":N,"limit":L,"offset":O,"hasMore":bool}
     *
     * Віддаються лише АКТИВНІ постачальники; за наявності storeId — ще й лише
     * ті, кому дозволена ця філія (SUP-03). Вердикт, як і на решті службових
     * маршрутів, ухвалює partner-service, а не споживач.
     *
     * `hasMore` рахується від ДЖЕРЕЛА вибірки (усі активні), а не від довжини
     * `items`: фільтр за філією може лишити сторінку порожньою, тоді як далі
     * ще є кого віддати. Клієнт зобовʼязаний гортати саме за `hasMore`.
     */
    #[Route('', name: 'internal_supplier_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $storeId = trim((string) $request->query->get('storeId', ''));
        // status=any — режим довідника walk-in: потрібні всі контрагенти,
        // включно з призупиненими, бо машина від них теж може приїхати.
        $activeOnly = 'any' !== strtolower(trim((string) $request->query->get('status', '')));

        $page = $this->suppliers->catalogPage(
            storeId: '' === $storeId ? null : $storeId,
            limit: $request->query->getInt('limit', 100),
            offset: $request->query->getInt('offset'),
            activeOnly: $activeOnly,
        );

        return new JsonResponse([
            'items' => array_map(
                static fn (SupplierAccessSnapshot $s): array => View::supplierAccess($s),
                $page['items'],
            ),
            'total' => $page['total'],
            'limit' => $page['limit'],
            'offset' => $page['offset'],
            'hasMore' => $page['hasMore'],
        ]);
    }

    /**
     * Стан постачальника: статус (SUP-02) і прив'язка до магазинів (SUP-03).
     */
    #[Route('/{supplierId}', name: 'internal_supplier_get', methods: ['GET'])]
    public function get(string $supplierId): JsonResponse
    {
        return new JsonResponse(View::supplierAccess($this->suppliers->accessSnapshot($supplierId)));
    }

    /**
     * Пряма відповідь «чи може цей постачальник бронювати в цій філії».
     *
     * Призупинений постачальник не має доступу навіть до магазину зі свого
     * whitelist — статус перевіряється першим, і саме він потрапляє в `reason`.
     */
    #[Route('/{supplierId}/store-access/{storeId}', name: 'internal_supplier_store_access', methods: ['GET'])]
    public function storeAccess(string $supplierId, string $storeId): JsonResponse
    {
        return new JsonResponse(View::supplierStoreAccess(
            $this->suppliers->accessSnapshot($supplierId),
            $storeId,
        ));
    }
}
