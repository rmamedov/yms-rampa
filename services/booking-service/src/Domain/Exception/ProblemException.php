<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use DomainException;

/**
 * Базова доменна помилка з канонічним кодом. Успадковується всіма
 * бізнес-винятками, які мають дійти до клієнта як problem+json.
 */
abstract class ProblemException extends DomainException implements DomainProblem
{
    /**
     * @return array<string, mixed>
     */
    public function problemExtensions(): array
    {
        return [];
    }
}
