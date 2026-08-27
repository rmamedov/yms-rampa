<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Application\Service\StoreSettingsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службовий API store-service для інших мікросервісів YMS.
 *
 * ТРАНСПОРТ. Викликається через внутрішній шлюз nginx на http://127.0.0.1:8081,
 * який слухає лише локальний інтерфейс і маршрутизує префікс /internal/v1/stores
 * саме сюди (infra/nginx-yms-internal.conf). Ззовні ці маршрути недосяжні:
 * публічні server-блоки /internal/ не обслуговують.
 *
 * АВТЕНТИФІКАЦІЯ. Службові маршрути НЕ проходять через auth_request і не отримують
 * заголовків ідентичності (X-User-Id, X-User-Role, X-Supplier-Id, X-Store-Ids,
 * X-Contour). Тому ActorResolver тут свідомо не викликається — інакше кожен виклик
 * booking-service отримував би 403 ACCESS_DENIED. Не додавайте сюди перевірку
 * ідентичності: захист цих маршрутів — мережевий, а не заголовковий.
 */
#[Route('/internal/v1/stores', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final readonly class InternalStoreController
{
    public function __construct(private StoreSettingsService $storeSettings)
    {
    }

    /**
     * Чинна конфігурація магазину для booking-service: геометрія сітки, параметри
     * движка бронювань, снапшот філії, а також накладання сітки — резерви (STC-40..43)
     * і блокування (STC-50..52).
     *
     * 404 STORE_NOT_FOUND / STORE_NOT_CONFIGURED — магазину для бронювання не існує.
     */
    #[Route('/{storeId}/settings', name: 'internal_store_settings', methods: ['GET'])]
    public function settings(string $storeId): JsonResponse
    {
        return new JsonResponse($this->storeSettings->settings($storeId));
    }
}
