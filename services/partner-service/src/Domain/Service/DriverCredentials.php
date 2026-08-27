<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\PartnerUser\PartnerUser;

/**
 * Результат створення водія або перегенерації пароля (SUP-DRV-03, SUP-DRV-04).
 *
 * Єдиний момент життя системи, коли пароль існує у відкритому вигляді:
 * він повертається викликачу РІВНО ОДИН РАЗ (модалка «Запишіть пароль —
 * повторно він не показується») і паралельно йде в SMS через
 * notification-service. Ніде не зберігається (DATA-35, DATA-21).
 */
final readonly class DriverCredentials
{
    public function __construct(
        public PartnerUser $driver,
        public string $login,
        public string $password,
    ) {
    }
}
