<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Ініціатор перепризначення (NOT-18).
 *
 * - Store    — магазин перевів машину на іншу рампу: SMS водію + e-mail постачальнику;
 * - Supplier — постачальник замінив водія/авто: SMS новому водію, e-mail
 *   постачальнику не дублюється (він і є ініціатор).
 */
enum ReassignmentInitiator: string
{
    case Store = 'store';
    case Supplier = 'supplier';
}
