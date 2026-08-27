<?php

declare(strict_types=1);

namespace App\Domain\Clock;

/**
 * Джерело часу для доменної логіки.
 *
 * DATA-01: усі моменти часу — в UTC; конвертація в Europe/Kyiv виконується
 * виключно на рівні API/фронтенду.
 */
interface Clock
{
    /** Поточний момент часу в UTC. */
    public function now(): \DateTimeImmutable;
}
