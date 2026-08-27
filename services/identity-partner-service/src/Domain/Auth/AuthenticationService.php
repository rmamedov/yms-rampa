<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\ClientType;
use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Clock\Clock;
use App\Domain\Exception\AccountDisabledException;
use App\Domain\Exception\InvalidCredentialsException;
use App\Domain\Security\LoginNormalizer;
use App\Domain\Security\PasswordHasher;
use App\Domain\Session\LoginThrottle;

/**
 * Логін партнерського контуру: `POST /api/supplier/v1/auth/login` та
 * `POST /api/driver/v1/auth/login` (AUTH-40).
 *
 * Послідовність відповідає sequence-діаграмі 3.5:
 *  1) нормалізація логіна (телефон → E.164 для driver-web, email → lowercase
 *     для supplier-web) — AUTH-23;
 *  2) перевірка лічильника блокувань — AUTH-50;
 *  3) пошук акаунта в `partner_accounts` — AUTH-29;
 *  4) перевірка хеша argon2id (+ rehash за потреби) — AUTH-60;
 *  5) перевірка активності акаунта — AUTH-12/AUTH-28;
 *  6) видача access 15 хв + refresh 30/90 днів — AUTH-27, DRV-07.
 */
final readonly class AuthenticationService
{
    public function __construct(
        private PartnerAccountRepository $accounts,
        private PasswordHasher $passwordHasher,
        private LoginNormalizer $loginNormalizer,
        private LoginThrottle $throttle,
        private SessionFactory $sessions,
        private Clock $clock,
    ) {
    }

    /**
     * @param bool $rememberMe прапорець «Запамʼятати мене» driver-web (AUTH-27),
     *                         за замовчуванням увімкнений
     *
     * @throws InvalidCredentialsException невірний логін/пароль або чужий застосунок
     * @throws AccountDisabledException    акаунт (або постачальник) деактивовано
     * @throws \App\Domain\Exception\AccountLockedException 5 невдалих спроб за 15 хв
     */
    public function login(
        ClientType $client,
        string $rawLogin,
        string $password,
        bool $rememberMe = true,
        ?string $ip = null,
        ?string $userAgent = null,
    ): AuthResult {
        // Некоректний формат логіна не має відрізнятися від неіснуючого
        // акаунта (AUTH-53), тому нормалізуємо «мʼяко».
        $login = $this->loginNormalizer->tryNormalizeForClient($client, $rawLogin) ?? '';

        // Ключ лічильника для нерозпізнаного логіна — сирий ввід, обрізаний
        // до розумної довжини: інакше блокування можна було б обійти
        // варіаціями форматування.
        $throttleKey = '' !== $login ? $login : mb_substr(trim($rawLogin), 0, 64);

        $this->throttle->assertNotLocked($throttleKey);

        $account = '' !== $login ? $this->accounts->findByLogin($login) : null;

        if (null === $account) {
            $this->throttle->registerFailure($throttleKey, 'unknown_login', $ip, $userAgent);

            throw new InvalidCredentialsException();
        }

        // DRV-10: у driver-web пускаємо лише роль driver, у supplier-web —
        // лише ролі постачальника. Причину не розкриваємо.
        if (!$client->allowsRole($account->role)) {
            $this->throttle->registerFailure($throttleKey, 'role_not_allowed_for_client', $ip, $userAgent);

            throw new InvalidCredentialsException();
        }

        if (!$this->passwordHasher->verify($account->passwordHash(), $password)) {
            $this->throttle->registerFailure($throttleKey, 'bad_password', $ip, $userAgent);

            throw new InvalidCredentialsException();
        }

        // AUTH-12: перевіряємо активність ПІСЛЯ пароля, щоб не перетворити
        // ендпоїнт на оракул існування акаунтів.
        if (!$account->isActive()) {
            $this->throttle->registerFailure($throttleKey, 'account_disabled', $ip, $userAgent);

            throw new AccountDisabledException();
        }

        $now = $this->clock->now();

        // AUTH-60: автоматичний rehash після посилення параметрів argon2id.
        if ($this->passwordHasher->needsRehash($account->passwordHash())) {
            $account->rehash($this->passwordHasher->hash($password), $now);
        }

        $account->markLoggedIn($now);
        $this->accounts->save($account);

        $this->throttle->registerSuccess($login, $ip, $userAgent);

        return $this->sessions->issue(
            account: $account,
            sid: $this->sessions->newSessionId(),
            refreshTtlSeconds: $client->refreshTtlSeconds($rememberMe),
            ip: $ip,
            userAgent: $userAgent,
        );
    }
}
