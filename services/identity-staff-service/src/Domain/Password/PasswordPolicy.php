<?php

declare(strict_types=1);

namespace App\Domain\Password;

/**
 * Політика паролів staff-контуру (розділ 3.2.2, AUTH-13).
 *
 * | Параметр           | Значення                                                        |
 * |--------------------|-----------------------------------------------------------------|
 * | Мінімальна довжина | 12 символів                                                     |
 * | Склад              | ≥1 велика літера, ≥1 мала, ≥1 цифра                             |
 * | Заборона           | збіг з email/імʼям; пароль зі словника поширених паролів        |
 * | Історія            | не повторювати останні 5 паролів                                |
 *
 * Додатково перевіряється тривіальна повторюваність символів
 * (напр. «Aaaaaaaaaa1»), бо такий пароль формально проходить склад,
 * але не має ентропії.
 *
 * AUTH-13: результат — перелік порушених правил, кожне окремим текстом українською.
 */
final readonly class PasswordPolicy
{
    public const int DEFAULT_MIN_LENGTH = 12;
    public const int MAX_LENGTH = 128;
    public const int MAX_REPEATED_RUN = 3;

    public function __construct(
        private PasswordHasher $hasher,
        private PasswordDenylist $denylist,
        private int $minLength = self::DEFAULT_MIN_LENGTH,
        private int $historyDepth = 5,
    ) {
    }

    /**
     * @param list<string> $passwordHistory хеші попередніх паролів (найновіший першим)
     *
     * @return list<string> перелік порушених правил; порожній масив = пароль відповідає політиці
     */
    public function validate(
        string $plainPassword,
        ?string $email = null,
        ?string $fullName = null,
        array $passwordHistory = [],
    ): array {
        $violations = [];

        $length = mb_strlen($plainPassword);

        if ($length < $this->minLength) {
            $violations[] = \sprintf('мінімальна довжина — %d символів', $this->minLength);
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = \sprintf('максимальна довжина — %d символів', self::MAX_LENGTH);
        }

        if (1 !== preg_match('/\p{Lu}/u', $plainPassword)) {
            $violations[] = 'потрібна щонайменше одна велика літера';
        }

        if (1 !== preg_match('/\p{Ll}/u', $plainPassword)) {
            $violations[] = 'потрібна щонайменше одна мала літера';
        }

        if (1 !== preg_match('/\d/', $plainPassword)) {
            $violations[] = 'потрібна щонайменше одна цифра';
        }

        if ($this->matchesIdentity($plainPassword, $email, $fullName)) {
            $violations[] = 'пароль не повинен збігатися з email або імʼям';
        }

        if ($this->hasRepetition($plainPassword)) {
            $violations[] = \sprintf(
                'пароль не повинен містити повторів: понад %d однакових символів поспіль або повторюваний блок',
                self::MAX_REPEATED_RUN,
            );
        }

        if ($this->denylist->contains($plainPassword)) {
            $violations[] = 'пароль занадто поширений — оберіть інший';
        }

        if ($this->matchesHistory($plainPassword, $passwordHistory)) {
            $violations[] = \sprintf('не можна повторювати останні %d паролів', $this->historyDepth);
        }

        return $violations;
    }

    /**
     * @param list<string> $passwordHistory
     *
     * @throws WeakPasswordException якщо пароль порушує політику (AUTH-13, 422 AUTH_WEAK_PASSWORD)
     */
    public function assertValid(
        string $plainPassword,
        ?string $email = null,
        ?string $fullName = null,
        array $passwordHistory = [],
    ): void {
        $violations = $this->validate($plainPassword, $email, $fullName, $passwordHistory);

        if ([] !== $violations) {
            throw new WeakPasswordException($violations);
        }
    }

    public function minLength(): int
    {
        return $this->minLength;
    }

    /**
     * Мінімальна довжина фрагмента, який має сенс шукати в паролі:
     * коротші (напр. локальна частина «a@silpo.ua») дали б хибні спрацювання.
     */
    private const int MIN_IDENTITY_FRAGMENT = 3;

    private function matchesIdentity(string $plainPassword, ?string $email, ?string $fullName): bool
    {
        $needle = mb_strtolower($plainPassword);

        foreach ([$email, $fullName] as $identity) {
            if (null === $identity || '' === trim($identity)) {
                continue;
            }

            $identity = mb_strtolower(trim($identity));

            if ($needle === $identity) {
                return true;
            }

            if (mb_strlen($identity) >= self::MIN_IDENTITY_FRAGMENT && str_contains($needle, $identity)) {
                return true;
            }

            // локальна частина email до «@» — найчастіший випадок збігу
            $at = strrpos($identity, '@');
            if (false !== $at) {
                $local = substr($identity, 0, $at);
                if (mb_strlen($local) >= self::MIN_IDENTITY_FRAGMENT && str_contains($needle, $local)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Повтори: «aaaa» (понад MAX_REPEATED_RUN однакових поспіль)
     * або пароль, повністю складений із повторюваного блоку («abcabcabcabc»).
     */
    private function hasRepetition(string $plainPassword): bool
    {
        if (1 === preg_match('/(.)\1{'.self::MAX_REPEATED_RUN.',}/u', $plainPassword)) {
            return true;
        }

        return 1 === preg_match('/^(.{1,4}?)\1{2,}$/u', $plainPassword);
    }

    /**
     * @param list<string> $passwordHistory
     */
    private function matchesHistory(string $plainPassword, array $passwordHistory): bool
    {
        foreach (\array_slice($passwordHistory, 0, $this->historyDepth) as $hash) {
            if ('' !== $hash && $this->hasher->verify($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }
}
