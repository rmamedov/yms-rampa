<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exception\AccountDisabledException;
use App\Domain\Auth\Exception\InvalidCredentialsException;
use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\Exception\TwoFactorInvalidException;
use App\Domain\Auth\Exception\TwoFactorRequiredException;
use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserRepository;
use App\Domain\Password\PasswordHasher;
use App\Domain\Password\PasswordPolicy;
use App\Domain\Shared\Clock;

/**
 * Флоу автентифікації staff-контуру (AUTH-40): логін, 2FA, refresh,
 * logout, зміна пароля.
 *
 * Порядок перевірок логіну повторює sequence-діаграму розділу 3.5:
 * throttler → пошук акаунта → перевірка argon2id → активність → 2FA → видача пари.
 */
final readonly class AuthenticationService
{
    /**
     * AUTH-17: challenge-токен 2FA живе 5 хвилин.
     */
    public const int TWO_FACTOR_CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private StaffUserRepository $users,
        private PasswordHasher $hasher,
        private PasswordPolicy $passwordPolicy,
        private TokenService $tokens,
        private LoginThrottler $throttler,
        private Clock $clock,
        private ?TwoFactorChallengeStore $challenges = null,
        private ?TotpVerifier $totp = null,
    ) {
    }

    /**
     * AUTH-10/AUTH-11/AUTH-12/AUTH-50.
     *
     * @throws Exception\AccountLockedException 423, якщо акаунт заблоковано перебором
     * @throws InvalidCredentialsException      401 для невірної пари та неіснуючого логіна
     * @throws AccountDisabledException         403 для деактивованого акаунта
     * @throws TwoFactorRequiredException       401 AUTH_2FA_REQUIRED із challenge-токеном
     */
    public function login(
        string $rawEmail,
        string $plainPassword,
        ?string $userAgent = null,
        ?string $ip = null,
    ): LoginResult {
        $loginKey = mb_strtolower(trim($rawEmail));

        // AUTH-50: блокування перевіряється ДО пошуку акаунта, тому поведінка
        // для неіснуючого логіна ідентична (крайовий випадок 3.6).
        $this->throttler->assertNotLocked($loginKey);

        try {
            $email = Email::fromString($rawEmail);
        } catch (ValidationException) {
            // AUTH-53: некоректний email не відрізняється від невірних креденшлів
            $this->throttler->registerFailure($loginKey, $ip, $userAgent, 'malformed_login');

            throw new InvalidCredentialsException();
        }

        $user = $this->users->findByEmail($email);

        if (null === $user || !$this->hasher->verify($plainPassword, $user->passwordHash())) {
            $this->throttler->registerFailure($loginKey, $ip, $userAgent, 'invalid_credentials');

            throw new InvalidCredentialsException();
        }

        // AUTH-12: деактивований запис не проходить навіть із правильним паролем
        if (!$user->isActive()) {
            $this->throttler->registerFailure($loginKey, $ip, $userAgent, 'account_disabled');

            throw new AccountDisabledException();
        }

        // AUTH-60: rehash після посилення параметрів argon2id
        if ($this->hasher->needsRehash($user->passwordHash())) {
            $user->rehashPassword($this->hasher->hash($plainPassword), $this->clock->now());
            $this->users->save($user);
        }

        // AUTH-17: двокроковий логін для акаунтів з увімкненою 2FA
        if ($user->isTwoFactorEnabled()) {
            throw $this->issueTwoFactorChallenge($user);
        }

        return $this->completeLogin($user, $loginKey, $userAgent, $ip);
    }

    /**
     * AUTH-17: другий крок логіну — challenge-токен + TOTP-код.
     */
    public function completeTwoFactorLogin(
        string $challengeToken,
        string $code,
        ?string $userAgent = null,
        ?string $ip = null,
    ): LoginResult {
        if (null === $this->challenges || null === $this->totp) {
            throw new TwoFactorInvalidException();
        }

        $now = $this->clock->now();
        // AUTH-62: challenge зберігається лише хешованим і є одноразовим
        $userId = $this->challenges->consume(hash('sha256', $challengeToken), $now);

        if (null === $userId) {
            throw new TwoFactorInvalidException();
        }

        $user = $this->users->findById($userId);

        if (null === $user || !$user->isActive()) {
            throw new AccountDisabledException();
        }

        $loginKey = $user->email()->value;
        $this->throttler->assertNotLocked($loginKey);

        if (null === $user->totpSecret() || !$this->totp->verify($user->totpSecret(), $code, $now)) {
            // AUTH-17: 5 невірних кодів поспіль — блокування на 15 хв (3.6)
            $this->throttler->registerFailure($loginKey, $ip, $userAgent, 'invalid_totp');

            throw new TwoFactorInvalidException();
        }

        return $this->completeLogin($user, $loginKey, $userAgent, $ip);
    }

    /**
     * AUTH-31 + RBAC-26: ротація пари. Нові токени містять АКТУАЛЬНІ клейми
     * (роль і скоуп із БД), а не ті, що були на момент логіну.
     */
    public function refresh(string $refreshToken, ?string $userAgent = null, ?string $ip = null): LoginResult
    {
        $claims = $this->tokens->consumeRefreshToken($refreshToken);

        $user = $this->users->findById($claims->subject);

        if (null === $user) {
            throw new InvalidTokenException('користувача не існує');
        }

        // RBAC-26: деактивація негайно інвалідує refresh-токени
        if (!$user->isActive()) {
            $this->tokens->revokeAllSessions($user->id());

            throw new AccountDisabledException();
        }

        return new LoginResult(
            $user,
            $this->tokens->issueFor($user, $claims->sessionId, $userAgent, $ip),
        );
    }

    /**
     * AUTH-32: logout відкликає refresh поточної сесії; «вийти з усіх пристроїв» —
     * усі sid користувача.
     */
    public function logout(string $refreshToken, bool $allDevices = false): void
    {
        if ($allDevices) {
            // Знаходимо власника токена, щоб погасити всі його ланцюжки sid
            $userId = $this->tokens->ownerOfRefreshHash(RefreshTokenRecord::hash($refreshToken));

            if (null !== $userId) {
                $this->tokens->revokeAllSessions($userId);
            }

            return;
        }

        $this->tokens->revokeSessionByRefreshToken($refreshToken);
    }

    /**
     * AUTH-14: зміна пароля вимагає введення поточного; після зміни всі
     * refresh-токени користувача, КРІМ поточної сесії, відкликаються.
     * AUTH-13: новий пароль перевіряється політикою, включно з історією 5 паролів.
     */
    public function changePassword(
        string $userId,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId = null,
    ): StaffUser {
        $user = $this->users->findById($userId);

        if (null === $user) {
            throw new InvalidTokenException('користувача не існує');
        }

        if (!$user->isActive()) {
            throw new AccountDisabledException();
        }

        if (!$this->hasher->verify($currentPassword, $user->passwordHash())) {
            throw new InvalidCredentialsException();
        }

        $this->passwordPolicy->assertValid(
            $newPassword,
            $user->email()->value,
            $user->fullName(),
            [$user->passwordHash(), ...$user->passwordHistory()],
        );

        $user->changePassword($this->hasher->hash($newPassword), $this->clock->now());
        $this->users->save($user);

        $this->tokens->revokeAllSessions($user->id(), $currentSessionId);

        return $user;
    }

    private function completeLogin(
        StaffUser $user,
        string $loginKey,
        ?string $userAgent,
        ?string $ip,
    ): LoginResult {
        $now = $this->clock->now();

        $this->throttler->registerSuccess($loginKey, $ip, $userAgent);

        $user->registerSuccessfulLogin($now);
        $this->users->save($user);

        return new LoginResult($user, $this->tokens->issueFor($user, null, $userAgent, $ip));
    }

    private function issueTwoFactorChallenge(StaffUser $user): TwoFactorRequiredException
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = $this->clock->now()->modify(
            \sprintf('+%d seconds', self::TWO_FACTOR_CHALLENGE_TTL_SECONDS),
        );

        $this->challenges?->put(hash('sha256', $token), $user->id(), $expiresAt);

        return new TwoFactorRequiredException($token, $expiresAt);
    }
}
