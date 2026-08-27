<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exception;

use App\Domain\Shared\DomainException;

/**
 * AUTH-50 / таблиця 3.7: 423 AUTH_ACCOUNT_LOCKED — 5 невдалих спроб поспіль,
 * блокування на 15 хвилин. Повертається незалежно від правильності пароля
 * в період блокування; для неіснуючого логіна поведінка ідентична.
 */
final class AccountLockedException extends DomainException
{
    public function __construct(private readonly \DateTimeImmutable $lockedUntil)
    {
        parent::__construct(
            'AUTH_ACCOUNT_LOCKED',
            423,
            'Забагато невдалих спроб. Обліковий запис тимчасово заблоковано, спробуйте через 15 хвилин.',
            ['lockedUntil' => $lockedUntil->format(\DATE_ATOM)],
        );
    }

    public function lockedUntil(): \DateTimeImmutable
    {
        return $this->lockedUntil;
    }
}
