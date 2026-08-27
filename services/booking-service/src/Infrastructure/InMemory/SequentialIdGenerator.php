<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\IdGenerator;

/**
 * Передбачувані ідентифікатори для тестів і демо-команд.
 */
final class SequentialIdGenerator implements IdGenerator
{
    private int $sequence = 0;

    public function __construct(private readonly string $prefix = 'bk-')
    {
    }

    public function generate(): string
    {
        return $this->prefix.\sprintf('%04d', ++$this->sequence);
    }
}
