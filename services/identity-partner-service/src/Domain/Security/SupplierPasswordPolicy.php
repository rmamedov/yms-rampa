<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Exception\WeakPasswordException;

/**
 * Політика паролів постачальника (AUTH-21).
 *
 * Мінімум 10 символів, літери + цифри, denylist поширених паролів, заборона
 * збігу з логіном. Порушення повертаються переліком українською (AUTH-13).
 *
 * Пароль водія під цю політику не підпадає: він генерується системою
 * (AUTH-24) і водій не може змінити його самостійно (AUTH-25).
 */
final readonly class SupplierPasswordPolicy
{
    public const int MIN_LENGTH = 10;

    /**
     * Локальний denylist. У проді підмінюється повним словником (≥100 тис.
     * записів, AUTH-13) через конструктор — тут лише базовий набір, щоб
     * сервіс був самодостатнім без зовнішнього файлу.
     *
     * @var list<string>
     */
    private const array DEFAULT_DENYLIST = [
        'password', 'password1', 'password123', 'qwerty123', 'qwertyuiop',
        '1234567890', '12345678901', 'admin12345', 'welcome123', 'iloveyou1',
        'silpo12345', 'rampa12345', 'supplier123', 'parol123456', 'ukraine123',
    ];

    /** @var array<string, true> */
    private array $denylist;

    /** @param list<string> $denylist */
    public function __construct(array $denylist = self::DEFAULT_DENYLIST)
    {
        $map = [];

        foreach ($denylist as $entry) {
            $map[strtolower($entry)] = true;
        }

        $this->denylist = $map;
    }

    /**
     * @throws WeakPasswordException з переліком порушених правил
     */
    public function assertValid(string $plainPassword, string $login = ''): void
    {
        $violations = $this->violations($plainPassword, $login);

        if ([] !== $violations) {
            throw new WeakPasswordException($violations);
        }
    }

    /** @return list<string> перелік порушених правил українською */
    public function violations(string $plainPassword, string $login = ''): array
    {
        $violations = [];

        if (mb_strlen($plainPassword) < self::MIN_LENGTH) {
            $violations[] = \sprintf('Пароль має містити щонайменше %d символів.', self::MIN_LENGTH);
        }

        if (!preg_match('/\p{L}/u', $plainPassword)) {
            $violations[] = 'Пароль має містити щонайменше одну літеру.';
        }

        if (!preg_match('/\d/', $plainPassword)) {
            $violations[] = 'Пароль має містити щонайменше одну цифру.';
        }

        if (isset($this->denylist[strtolower($plainPassword)])) {
            $violations[] = 'Пароль занадто поширений — оберіть інший.';
        }

        if ('' !== $login && strtolower($plainPassword) === strtolower($login)) {
            $violations[] = 'Пароль не може збігатися з логіном.';
        }

        return $violations;
    }
}
