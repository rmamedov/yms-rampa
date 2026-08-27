<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Shared\IdGenerator;

/**
 * Передбачуваний генератор ідентифікаторів для тестів: `sp-0001`, `sp-0002`…
 * У проді використовується Uuid4Generator (DATA-05).
 */
final class SequenceIdGenerator implements IdGenerator
{
    private int $counter = 0;

    public function __construct(private readonly string $prefix = 'id')
    {
    }

    public function generate(): string
    {
        return \sprintf('%s-%04d', $this->prefix, ++$this->counter);
    }
}
