<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\Service\DriverService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Службовий довідник водіїв для booking-service.
 *
 * КОНТРАКТ:
 *
 *   GET /internal/v1/drivers?ids=du-1,du-2
 *   200 {"items":[{"driverId","fullName","phone","supplierId","active"}]}
 *
 * НАВІЩО. Бронювання зберігає лише `driverId` ПРОФІЛЮ (partner_users), тому
 * картка прибуття в контурі магазину без цього маршруту показувала б замість
 * водія голий ідентифікатор. Пакетний вигляд («ids через кому») навмисний:
 * дошка магазину читає до кількох десятків бронювань за раз, і поштучні
 * виклики перетворили б один екран на десятки звернень до сусіда.
 *
 * Невідомі ідентифікатори просто відсутні у відповіді — 404 тут не буває:
 * питання «хто ці водії» коректне навіть тоді, коли частина профілів зникла.
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: маршрут обслуговує лише внутрішній
 * шлюз nginx на 127.0.0.1:8081 (map `$yms_internal_service`). Через
 * auth_request такі запити не проходять і заголовків ідентичності не мають,
 * тому ActorResolver тут свідомо НЕ викликається.
 */
#[Route('/internal/v1/drivers')]
final readonly class InternalDriverController
{
    /**
     * Стеля на один виклик. Дошка магазину за добу фізично не має більше
     * водіїв, ніж слотів; довший перелік — ознака помилки клієнта, а не
     * підстава сканувати сховище.
     */
    private const int MAX_IDS = 200;

    public function __construct(private DriverService $drivers)
    {
    }

    #[Route('', name: 'internal_driver_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $ids = self::idList((string) $request->query->get('ids', ''));

        return new JsonResponse([
            'items' => array_map(
                static fn (PartnerUser $driver): array => [
                    'driverId' => $driver->id(),
                    'fullName' => $driver->fullName(),
                    'phone' => $driver->phone(),
                    'supplierId' => $driver->supplierId(),
                    'active' => $driver->isActive(),
                ],
                [] === $ids ? [] : $this->drivers->findByIds($ids),
            ),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function idList(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $id): bool => '' !== $id,
        )));

        return \array_slice($ids, 0, self::MAX_IDS);
    }
}
