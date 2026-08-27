<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Access\Actor;

/**
 * Хто скасував бронювання (поле cancellation.by, розділ 10.3.1).
 */
enum CancelledBy: string
{
    case Supplier = 'supplier';
    case Store = 'store';
    case Admin = 'admin';

    public static function fromActor(Actor $actor): self
    {
        if ($actor->role->isSupplier()) {
            return self::Supplier;
        }

        return $actor->role->isStoreStaff() ? self::Store : self::Admin;
    }
}
