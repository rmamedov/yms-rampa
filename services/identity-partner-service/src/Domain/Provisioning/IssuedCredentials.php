<?php

declare(strict_types=1);

namespace App\Domain\Provisioning;

use App\Domain\Account\AccountProfile;

/**
 * Результат створення акаунта / перегенерації пароля.
 *
 * AUTH-24: згенерований пароль повертається РІВНО ОДИН РАЗ — далі його ніде
 * не можна прочитати, лише перегенерувати (AUTH-25). Якщо пароль задав
 * викликач (постачальник), `passwordPlain` порожній.
 */
final readonly class IssuedCredentials
{
    public function __construct(
        public AccountProfile $profile,
        public ?string $passwordPlain,
        public bool $passwordGenerated,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = $this->profile->toArray();
        $payload['passwordGenerated'] = $this->passwordGenerated;

        // Пароль потрапляє у відповідь лише в момент генерації (AUTH-24);
        // у логи це тіло не пишеться (AUTH-61).
        if ($this->passwordGenerated) {
            $payload['passwordPlain'] = $this->passwordPlain;
        }

        return $payload;
    }
}
