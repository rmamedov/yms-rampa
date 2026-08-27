<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Доменна помилка, яку HTTP-шар вміє перетворити на RFC 7807
 * application/problem+json з розширеннями code і requestId.
 *
 * Домен не залежить від HTTP: він лише декларує канонічний код помилки
 * та статус, який їй відповідає у специфікації.
 */
interface DomainProblem
{
    /** Канонічний код помилки, напр. VEHICLE_TOO_HEAVY. */
    public function errorCode(): string;

    /** HTTP-статус, зафіксований у специфікації для цього коду. */
    public function httpStatus(): int;

    /**
     * Додаткові поля відповіді (деталі конфлікту тощо).
     *
     * @return array<string, mixed>
     */
    public function problemExtensions(): array;
}
