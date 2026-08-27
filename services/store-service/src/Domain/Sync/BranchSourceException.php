<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Shared\DomainException;

/**
 * Джерело даних MCP недоступне або віддало часткову вибірку (INT-12, SYNC-04).
 * Синк фіксується як failed, БД не змінюється.
 */
final class BranchSourceException extends DomainException
{
    public function httpStatus(): int
    {
        return 502;
    }

    public function title(): string
    {
        return 'Джерело даних MCP недоступне';
    }

    public static function unavailable(string $reason): self
    {
        return new self(
            \sprintf('Не вдалося отримати довідник філій з MCP: %s', $reason),
            'MCP_SOURCE_UNAVAILABLE',
        );
    }

    public static function partialPagination(int $received, int $expected): self
    {
        return new self(
            \sprintf('Обрив пагінації MCP: отримано %d із %d записів', $received, $expected),
            'MCP_PARTIAL_FETCH',
        );
    }
}
