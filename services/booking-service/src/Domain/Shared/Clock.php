<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;

/**
 * Джерело поточного часу. Домен ніколи не звертається до time()/new DateTime()
 * напряму, інакше правила з часом (lead time, дедлайни, no-show) неможливо
 * протестувати детерміновано.
 *
 * Час завжди повертається в UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
