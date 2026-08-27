<?php

declare(strict_types=1);

namespace App\Infrastructure\Store;

use App\Domain\Exception\UpstreamUnavailableException;
use App\Domain\Slot\ReservedSlotRule;
use App\Domain\Slot\SlotBlock;
use App\Domain\Slot\SlotOverlayProvider;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use TypeError;

/**
 * Накладання сітки (DATA-11) з реального store-service: розклади резервів
 * (`reservedSlotRules`) і ручні блокування (`slotBlocks`).
 *
 * Джерело — те саме тіло GET /internal/v1/stores/{id}/settings, що й у
 * HttpStoreConfigProvider, тому обидва адаптери отримують ОДИН екземпляр
 * StoreServiceClient: його памʼятний кеш перетворює три звернення на одну
 * побудову сітки (settings + blocksFor + reservedRulesFor) в один мережевий
 * виклик.
 *
 * store-service уже віддає лише чинні накладання в межах горизонту
 * бронювання (неактивні правила, прострочені validTo і зняті блокування
 * відсіяні на його боці), тому тут лишається тільки перетин із запитаною
 * добою — фільтр, якого сусід не знає.
 */
final readonly class HttpSlotOverlayProvider implements SlotOverlayProvider
{
    public function __construct(private StoreServiceClient $client)
    {
    }

    public function blocksFor(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $payload = $this->client->fetchStore($storeId);

        // Магазину немає — накладати нічого; «немає магазину» скаже
        // StoreConfigProvider, у сітку цей виклик уже не дійде.
        if (null === $payload) {
            return [];
        }

        $blocks = [];

        foreach ((array) ($payload['slotBlocks'] ?? []) as $raw) {
            foreach (self::blocks($storeId, (array) $raw) as $block) {
                if ($block->from < $to && $block->to > $from) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    public function reservedRulesFor(string $storeId): array
    {
        $payload = $this->client->fetchStore($storeId);

        if (null === $payload) {
            return [];
        }

        $rules = [];

        foreach ((array) ($payload['reservedSlotRules'] ?? []) as $raw) {
            $raw = (array) $raw;

            try {
                // Відсутнє поле навмисно віддається домену порожнім: він знає,
                // що саме обовʼязкове, і скаже це зрозумілою помилкою.
                $rules[] = new ReservedSlotRule(
                    supplierId: (string) ($raw['supplierId'] ?? ''),
                    rampId: (string) ($raw['rampId'] ?? ''),
                    slotStartTime: (string) ($raw['slotStartTime'] ?? ''),
                    // Рівно одне з двох не null: щотижневе правило або разова дата.
                    dayOfWeek: isset($raw['dayOfWeek']) ? (int) $raw['dayOfWeek'] : null,
                    date: isset($raw['date']) ? (string) $raw['date'] : null,
                    // Локальні дати магазину Y-m-d: домен порівнює їх з датою слота рядково.
                    validFrom: isset($raw['validFrom']) ? (string) $raw['validFrom'] : null,
                    validTo: isset($raw['validTo']) ? (string) $raw['validTo'] : null,
                    active: (bool) ($raw['active'] ?? true),
                );
            } catch (InvalidArgumentException|TypeError $error) {
                throw UpstreamUnavailableException::badResponse('store-service', $error->getMessage(), $error);
            }
        }

        return $rules;
    }

    /**
     * Одне блокування store-service може накривати кілька рамп, а доменний
     * SlotBlock — рівно одну (або всі через rampId = null). Тому запис
     * розгортається у список.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<SlotBlock>
     */
    private static function blocks(string $storeId, array $raw): array
    {
        $from = self::moment($raw['blockFrom'] ?? null);
        $to = self::moment($raw['blockTo'] ?? null);
        $reason = isset($raw['reason']) ? (string) $raw['reason'] : null;

        // Порожній rampIds разом із coversAllRamps=true означає «всі рампи».
        $rampIds = (bool) ($raw['coversAllRamps'] ?? false)
            ? [null]
            : array_map(static fn (mixed $id): string => (string) $id, (array) ($raw['rampIds'] ?? []));

        $blocks = [];

        foreach ($rampIds as $rampId) {
            try {
                $blocks[] = new SlotBlock($storeId, $rampId, $from, $to, $reason);
            } catch (InvalidArgumentException|TypeError $error) {
                throw UpstreamUnavailableException::badResponse('store-service', $error->getMessage(), $error);
            }
        }

        return $blocks;
    }

    private static function moment(mixed $raw): DateTimeImmutable
    {
        // Порожній рядок DateTimeImmutable мовчки читає як «зараз», тому
        // відсутня межа має впасти явно, а не перетворитися на блокування
        // з моменту запиту.
        if (!\is_string($raw) || '' === $raw) {
            throw UpstreamUnavailableException::badResponse('store-service', 'блокування без межі часу');
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $error) {
            throw UpstreamUnavailableException::badResponse(
                'store-service',
                \sprintf('некоректна мітка часу блокування "%s"', $raw),
                $error,
            );
        }
    }
}
