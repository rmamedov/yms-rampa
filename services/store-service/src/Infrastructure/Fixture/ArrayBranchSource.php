<?php

declare(strict_types=1);

namespace App\Infrastructure\Fixture;

use App\Domain\Sync\BranchSource;
use App\Domain\Sync\BranchSourceException;

/**
 * Джерело з готового масиву записів — для тестів синхронізації
 * та для емуляції збоїв MCP (INT-12, SYNC-04).
 */
final class ArrayBranchSource implements BranchSource
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private array $rows = [],
        private ?BranchSourceException $failure = null,
    ) {
    }

    public function fetchAll(): iterable
    {
        if ($this->failure instanceof BranchSourceException) {
            throw $this->failure;
        }

        return $this->rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function replace(array $rows): void
    {
        $this->rows = $rows;
        $this->failure = null;
    }

    public function fail(BranchSourceException $failure): void
    {
        $this->failure = $failure;
    }

    public function describe(): string
    {
        return 'array';
    }
}
