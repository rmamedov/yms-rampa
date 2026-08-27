<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Генератор первинних ідентифікаторів.
 *
 * DATA-05: первинні ідентифікатори сутностей — UUID v4 у полі `_id` (BSON string).
 */
interface IdGenerator
{
    public function generate(): string;
}
