<?php

declare(strict_types=1);

namespace App\Infrastructure\Supplier;

use App\Domain\Booking\Exception\SupplierNotAllowedException;
use App\Domain\Supplier\SupplierDirectory;
use App\Domain\Supplier\SupplierInfo;
use App\Infrastructure\Internal\InternalJsonGateway;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Довідник постачальників поверх partner-service (BOOK-02).
 *
 * Контракт сусіда:
 *   GET {base}/internal/v1/suppliers/{supplierId}
 *   200 {"supplierId","name","status":"active|suspended","allStores":bool,
 *        "allowedStoreIds":[…]};
 *   GET {base}/internal/v1/suppliers/{supplierId}/store-access/{storeId}
 *   200 те саме + {"storeId","allowed":bool,
 *        "reason":null|"SUPPLIER_SUSPENDED"|"SUPPLIER_STORE_NOT_ALLOWED"};
 *   404 application/problem+json з code = SUPPLIER_NOT_FOUND.
 *
 * ВЕРДИКТ ухвалює partner-service, а не booking-service. Відмова приходить
 * як 200 з allowed=false, і саме її ми перекладаємо в SUPPLIER_NOT_ALLOWED.
 * Локально перевіряти allowedStoreIds не можна: у partner-service порожній
 * список при allStores=false означає «жодного магазину», а доменний
 * SupplierInfo::hasAccessTo читає порожній список як «усі магазини» —
 * протилежно. Тому рішення завжди береться з поля `allowed`.
 */
final class HttpSupplierDirectory implements SupplierDirectory
{
    /** Розмір сторінки довідника; більший partner-service обрізає до 200. */
    private const int PAGE_SIZE = 100;

    /** Захисна стеля обходу: 10 000 постачальників — це вже не мережа, а збій. */
    private const int MAX_SCAN = 10_000;

    private readonly InternalJsonGateway $gateway;

    /**
     * Знімки постачальників у межах одного HTTP-запиту.
     *
     * Потрібен окремо від кешу шлюзу (той ключується шляхом): відповідь
     * store-access є надмножиною відповіді на /suppliers/{id}, тож після
     * перевірки доступу наступний find() того самого постачальника
     * обслуговується без другого виклику до сусіда.
     *
     * @var array<string, SupplierInfo|null>
     */
    private array $known = [];

    public function __construct(
        HttpClientInterface $http,
        string $baseUrl,
        float $timeoutSeconds = InternalJsonGateway::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->gateway = new InternalJsonGateway($http, 'partner-service', $baseUrl, $timeoutSeconds);
    }

    public function find(string $supplierId): ?SupplierInfo
    {
        if (\array_key_exists($supplierId, $this->known)) {
            return $this->known[$supplierId];
        }

        $payload = $this->gateway->getJson('/internal/v1/suppliers/'.InternalJsonGateway::segment($supplierId));

        return $this->known[$supplierId] = null === $payload ? null : self::toInfo($supplierId, $payload);
    }

    public function assertMayBookAt(string $supplierId, string $storeId): SupplierInfo
    {
        $payload = $this->gateway->getJson(\sprintf(
            '/internal/v1/suppliers/%s/store-access/%s',
            InternalJsonGateway::segment($supplierId),
            InternalJsonGateway::segment($storeId),
        ));

        if (null === $payload) {
            $this->known[$supplierId] = null;

            throw new SupplierNotAllowedException($supplierId, $storeId, 'Постачальника не знайдено');
        }

        $supplier = $this->known[$supplierId] = self::toInfo($supplierId, $payload);

        if (true === ($payload['allowed'] ?? null)) {
            return $supplier;
        }

        throw match ((string) ($payload['reason'] ?? '')) {
            // SUP-02: призупинений (або архівований) постачальник не бронює
            // навіть у «своїй» філії — статус перевіряється першим.
            'SUPPLIER_SUSPENDED' => SupplierNotAllowedException::suspended($supplierId, $storeId),
            default => new SupplierNotAllowedException($supplierId, $storeId),
        };
    }

    /**
     * ПОВНИЙ довідник постачальників філії: клієнт гортає всі сторінки сусіда.
     *
     * Гортання йде за `hasMore`, а не за довжиною `items`: partner-service
     * фільтрує сторінку за доступом до філії вже після вибірки, тож сторінка
     * цілком може виявитися порожньою, тоді як далі ще є кого віддати.
     * Зупинка на першій непорожній сторінці — це рівно той дефект «показано
     * лише першу сторінку», через який приймальник не знаходив контрагента.
     */
    public function listForStore(string $storeId): array
    {
        $suppliers = [];

        for ($offset = 0; $offset < self::MAX_SCAN; $offset += self::PAGE_SIZE) {
            $payload = $this->gateway->getJson(\sprintf(
                '/internal/v1/suppliers?storeId=%s&limit=%d&offset=%d',
                rawurlencode($storeId),
                self::PAGE_SIZE,
                $offset,
            ));

            // 404 службового маршруту означає «переліку немає»; форма walk-in
            // має відкритися з порожнім довідником, а не впасти.
            if (null === $payload) {
                break;
            }

            foreach ((array) ($payload['items'] ?? []) as $item) {
                $item = (array) $item;
                $supplierId = (string) ($item['supplierId'] ?? '');

                if ('' !== $supplierId) {
                    $suppliers[] = $this->known[$supplierId] = self::toInfo($supplierId, $item);
                }
            }

            if (true !== ($payload['hasMore'] ?? false)) {
                break;
            }
        }

        usort($suppliers, static fn (SupplierInfo $a, SupplierInfo $b): int => strcmp($a->name, $b->name));

        return $suppliers;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function toInfo(string $supplierId, array $payload): SupplierInfo
    {
        $allStores = (bool) ($payload['allStores'] ?? false);

        return new SupplierInfo(
            supplierId: (string) ($payload['supplierId'] ?? $supplierId),
            name: (string) ($payload['name'] ?? ''),
            active: 'active' === (string) ($payload['status'] ?? ''),
            // При allStores=true partner-service віддає порожній перелік —
            // це рівно те, як SupplierInfo кодує «доступ до всіх філій».
            allowedStoreIds: $allStores
                ? []
                : array_map(
                    static fn (mixed $id): string => (string) $id,
                    array_values((array) ($payload['allowedStoreIds'] ?? [])),
                ),
        );
    }
}
