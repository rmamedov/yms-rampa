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

    /** DRV: водій діє лише щодо точок власного маршрутного листа. */
    public static function foreignRouteSheet(): self
    {
        return new self('Бронювання не входить до маршрутного листа цього водія');
    }

    /** Контур водія закритий для решти ролей — у них власні контури. */
    public static function driverContourOnly(): self
    {
        return new self('Контур водія доступний лише користувачам з роллю «driver»');
    }

    /**
     * DRV: обліковий запис водія не звʼязаний із профілем
     * (порожній X-Driver-Profile-Id) — діяти в контурі водія нічим.
     */
    public static function driverWithoutProfile(): self
    {
        return new self('Обліковий запис водія не привʼязаний до профілю водія');
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
