<?php

declare(strict_types=1);

namespace App\Infrastructure\Fixture;

use App\Domain\Sync\BranchSource;
use App\Domain\Sync\BranchSourceException;

/**
 * Джерело довідника філій з файлу-фікстури (знімок MCP silpo_list_branches).
 *
 * Використовується для первинного імпорту (yms:branches:import) і в тестах,
 * поки реальний MCP-адаптер недоступний. Контракт полів — 11.1.1.
 */
final readonly class FixtureBranchSource implements BranchSource
{
    public function __construct(
        private string $path,
    ) {
    }

    public function fetchAll(): iterable
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw BranchSourceException::unavailable(\sprintf('файл фікстури «%s» недоступний', $this->path));
        }

        $raw = file_get_contents($this->path);

        if (false === $raw) {
            throw BranchSourceException::unavailable(\sprintf('не вдалося прочитати «%s»', $this->path));
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw BranchSourceException::unavailable('некоректний JSON фікстури: '.$e->getMessage());
        }

        $branches = $decoded['branches'] ?? null;

        if (!\is_array($branches)) {
            throw BranchSourceException::unavailable('у фікстурі відсутній масив branches');
        }

        // INT-01: вибірка вважається повною лише якщо кількість записів збігається з total.
        $declaredTotal = $decoded['total'] ?? null;

        if (\is_int($declaredTotal) && $declaredTotal !== \count($branches)) {
            throw BranchSourceException::partialPagination(\count($branches), $declaredTotal);
        }

        foreach ($branches as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                yield $row;
            }
        }
    }

    public function describe(): string
    {
        return 'fixture:'.basename($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }
}
