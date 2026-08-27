<?php

declare(strict_types=1);

namespace App\Infrastructure\Driver;

use App\Domain\Driver\DriverDirectory;
use App\Domain\Driver\DriverInfo;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\Internal\InternalJsonGateway;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Довідник профілів водіїв поверх partner-service.
 *
 * Контракт сусіда:
 *   GET {base}/internal/v1/drivers?ids=du-1,du-2
 *   200 {"items":[{"driverId","fullName","phone","supplierId","active"}]}
 *
 * ЗАПИТИ ПАКЕТНІ. Дошка магазину за добу містить десятки бронювань; поштучні
 * виклики перетворили б один екран на десятки звернень до сусіда, тому
 * ідентифікатори йдуть одним переліком і ріжуться на пачки.
 *
 * НЕДОСТУПНИЙ СУСІД НЕ ЛАМАЄ ДОШКУ. Імʼя і телефон водія — довідкове
 * збагачення картки, від якого не залежить жодне доменне правило. Якщо
 * partner-service мовчить, дошка має показатися без імен, тому виняток
 * транспорту тут гаситься: 503 на весь екран через відсутню підказку —
 * гірша поведінка, ніж картка з голим ідентифікатором.
 */
final readonly class HttpDriverDirectory implements DriverDirectory
{
    /** Стеля partner-service на один виклик — не більше 200 ідентифікаторів. */
    private const int BATCH = 200;

    private InternalJsonGateway $gateway;

    public function __construct(
        HttpClientInterface $http,
        string $baseUrl,
        float $timeoutSeconds = InternalJsonGateway::DEFAULT_TIMEOUT_SECONDS,
    ) {
        $this->gateway = new InternalJsonGateway($http, 'partner-service', $baseUrl, $timeoutSeconds);
    }

    public function findMany(array $driverIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(trim(...), $driverIds),
            static fn (string $id): bool => '' !== $id,
        )));

        if ([] === $ids) {
            return [];
        }

        $found = [];

        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            foreach ($this->fetch($chunk) as $driverId => $driver) {
                $found[$driverId] = $driver;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, DriverInfo>
     */
    private function fetch(array $ids): array
    {
        try {
            $payload = $this->gateway->getJson(
                '/internal/v1/drivers?ids='.rawurlencode(implode(',', $ids))
            );
        } catch (UpstreamUnavailableException) {
            return [];
        }

        $drivers = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            $item = (array) $item;
            $driverId = (string) ($item['driverId'] ?? '');

            if ('' === $driverId) {
                continue;
            }

            $phone = (string) ($item['phone'] ?? '');
            $drivers[$driverId] = new DriverInfo(
                driverId: $driverId,
                fullName: (string) ($item['fullName'] ?? ''),
                phone: '' === $phone ? null : $phone,
                supplierId: isset($item['supplierId']) ? (string) $item['supplierId'] : null,
                active: (bool) ($item['active'] ?? true),
            );
        }

        return $drivers;
    }
}
