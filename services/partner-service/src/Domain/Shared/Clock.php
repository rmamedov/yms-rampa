<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Джерело поточного часу. Домен ніколи не звертається до `new \DateTimeImmutable()`
 * напряму, щоб час був детермінованим у тестах.
 *
 * DATA-01: усі мітки часу — в UTC; конвертація в Europe/Kyiv лише на межі API/UI.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
