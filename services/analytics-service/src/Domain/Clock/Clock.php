<?php

declare(strict_types=1);

namespace App\Domain\Clock;

/**
 * Джерело поточного часу. Домен не звертається до системного годинника
 * напряму, щоб пресети періодів («сьогодні», «7 днів», «30 днів») були
 * детерміновано тестованими.
 */
interface Clock
{
    /** Поточний момент у UTC. */
    public function now(): \DateTimeImmutable;
}
