<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Контур, у якому діє ініціатор (заголовок X-Contour єдиного контракту
 * ідентичності): співробітники мережі і магазину — `staff`, кабінет
 * постачальника і водій — `partner`.
 *
 * `system` заголовком не приходить і прийти не може: так позначається дія
 * планового завдання самого booking-service (авто-no_show, NOSH-01), у якої
 * людини-виконавця немає взагалі.
 */
enum Contour: string
{
    case Staff = 'staff';
    case Partner = 'partner';
    case System = 'system';

    public static function forActor(Actor $actor): self
    {
        return $actor->system ? self::System : $actor->role->contour();
    }

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Контур співробітників',
            self::Partner => 'Контур партнерів',
            self::System => 'Планове завдання',
        };
    }
}
