<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * Довідник причин відмови в прийомі (ST-07). Для «інше» коментар обовʼязковий.
 */
enum RejectionReason: string
{
    case OverWeight = 'перевищення тоннажу';
    case CargoMismatch = 'невідповідність вантажу';
    case MissingDocuments = 'відсутні документи';
    case Other = 'інше';

    public function requiresComment(): bool
    {
        return self::Other === $this;
    }
}
