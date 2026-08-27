<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-02 / RBAC-19 / таблиця 3.7: 401 AUTH_TOKEN_INVALID.
 *
 * Сюди потрапляє і токен ЧУЖОГО контуру: підпис не валідується ключем
 * staff-контуру (або клейми iss/aud/contour не збігаються), тож це помилка
 * автентифікації, а не авторизації.
 */
final class InvalidTokenException extends DomainException
{
    public function __construct(private readonly string $technicalReason = '')
    {
        parent::__construct('AUTH_TOKEN_INVALID', 401, 'Помилка автентифікації. Увійдіть повторно.');
    }

    /**
     * AUTH-53: технічні деталі — тільки в логах, не в тілі відповіді.
     */
    public function technicalReason(): string
    {
        return $this->technicalReason;
    }
}
