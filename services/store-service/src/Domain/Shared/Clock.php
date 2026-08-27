<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Джерело поточного часу. Домен ніколи не викликає time()/new DateTime() напряму,
 * щоб правила з датами (DATA-01, STC-13, STC-60) були детерміновано тестованими.
 */
interface Clock
{
    /** Поточний момент у UTC. */
    public function now(): \DateTimeImmutable;
}
