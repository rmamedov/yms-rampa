<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Shared\ConflictException;

/**
 * Версіоноване сховище конфігурацій у памʼяті (DATA-09: тільки додавання нових версій).
 */
final class InMemoryStoreConfigurationRepository implements StoreConfigurationRepository
{
    /** @var array<string, StoreConfiguration> */
    private array $configs = [];

    public function save(StoreConfiguration $configuration): void
    {
        foreach ($this->configs as $existing) {
            if ($existing->storeId !== $configuration->storeId) {
                continue;
            }

            if ($existing->version === $configuration->version && $existing->id !== $configuration->id) {
                throw new ConflictException(
                    \sprintf('Версія %d конфігурації магазину вже існує', $configuration->version),
                    'CONFIG_VERSION_CONFLICT',
                );
            }
        }

        $this->configs[$configuration->id] = $configuration;
    }

    public function find(string $id): ?StoreConfiguration
    {
        return $this->configs[$id] ?? null;
    }

    public function findAllForStore(string $storeId): array
    {
        $result = array_values(array_filter(
            $this->configs,
            static fn (StoreConfiguration $c): bool => $c->storeId === $storeId,
        ));

        usort($result, static fn (StoreConfiguration $a, StoreConfiguration $b): int => $b->version <=> $a->version);

        return $result;
    }

    public function findEffectiveAt(string $storeId, \DateTimeImmutable $at): ?StoreConfiguration
    {
        $candidates = array_filter(
            $this->findAllForStore($storeId),
            static fn (StoreConfiguration $c): bool => $c->isEffectiveAt($at),
        );

        $best = null;

        foreach ($candidates as $candidate) {
            if (!$best instanceof StoreConfiguration
                || $candidate->effectiveFrom > $best->effectiveFrom
                || ($candidate->effectiveFrom == $best->effectiveFrom && $candidate->version > $best->version)
            ) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public function findLatest(string $storeId): ?StoreConfiguration
    {
        return $this->findAllForStore($storeId)[0] ?? null;
    }

    public function nextVersion(string $storeId): int
    {
        return ($this->findLatest($storeId)?->version ?? 0) + 1;
    }

    public function configuredStoreIds(\DateTimeImmutable $at): array
    {
        $storeIds = [];

        foreach ($this->configs as $config) {
            $storeIds[$config->storeId] = true;
        }

        $result = [];

        foreach (array_keys($storeIds) as $storeId) {
            if ($this->findEffectiveAt($storeId, $at)?->isComplete() ?? false) {
                $result[] = $storeId;
            }
        }

        return $result;
    }
}
