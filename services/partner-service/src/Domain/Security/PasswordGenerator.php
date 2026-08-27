<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Порт генерації тимчасових паролів водіїв (SUP-DRV-03, SUP-DRV-04).
 */
interface PasswordGenerator
{
    public function generate(): string;
}
