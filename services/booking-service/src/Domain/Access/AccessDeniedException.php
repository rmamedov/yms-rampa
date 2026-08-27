<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Exception\ProblemException;

/**
 * Роль ініціатора не має права на цю дію в цьому контурі
 * (матриця RBAC, розділ 4).
 */
final class AccessDeniedException extends ProblemException
{
    public const string ERROR_CODE = 'ACCESS_DENIED';

    public function __construct(string $message = 'Недостатньо прав для цієї дії')
    {
        parent::__construct($message);
    }

    public static function forWalkIn(): self
    {
        return new self('Реєструвати позапланове прибуття може лише магазин або адміністратор мережі');
    }

    public static function foreignBooking(): self
    {
        return new self('Бронювання належить іншому постачальнику');
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
