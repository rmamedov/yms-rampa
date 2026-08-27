<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exception;

use App\Domain\Shared\DomainException;

/**
 * RBAC-18: читання одиничного ресурсу поза скоупом не розкриває його існування —
 * 404 RESOURCE_NOT_FOUND (таблиця 4.10, сценарій 5).
 */
final class ResourceNotFoundException extends DomainException
{
    public function __construct(string $resource = 'resource')
    {
        parent::__construct(
            'RESOURCE_NOT_FOUND',
            404,
            'Ресурс не знайдено',
            ['resource' => $resource],
        );
    }
}
