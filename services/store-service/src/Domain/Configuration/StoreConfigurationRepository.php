<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

/**
 * Сховище версіонованих конфігурацій магазину (10.2.2).
 */
interface StoreConfigurationRepository
{
    /** DATA-09: збереження завжди додає НОВУ версію; оновлення існуючої заборонене. */
    public function save(StoreConfiguration $configuration): void;

    public function find(string $id): ?StoreConfiguration;

    /** @return list<StoreConfiguration> у порядку спадання версії */
    public function findAllForStore(string $storeId): array;

    /** Чинна версія — з максимальним effectiveFrom ≤ $at. */
    public function findEffectiveAt(string $storeId, \DateTimeImmutable $at): ?StoreConfiguration;

    /** Остання за версією конфігурація, включно з майбутніми (effectiveFrom > now). */
    public function findLatest(string $storeId): ?StoreConfiguration;

    public function nextVersion(string $storeId): int;

    /**
     * Магазини, що мають чинну і повну конфігурацію на момент $at (STL-04).
     *
     * @return list<string>
     */
    public function configuredStoreIds(\DateTimeImmutable $at): array;
}
