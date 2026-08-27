<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Джерело часу для доменної логіки.
 *
 * Усі мітки часу — виключно в UTC (DATA-01); конвертація в Europe/Kyiv
 * виконується лише на рівні API/фронтенду.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
