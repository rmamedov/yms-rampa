<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Auth\AuthenticationService;
use App\Domain\Auth\LoginThrottler;
use App\Domain\Auth\TokenService;
use App\Domain\Auth\TotpVerifier;
use App\Domain\Identity\AccessDecider;
use App\Domain\Identity\Contour;
use App\Domain\Identity\Email;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Password\PasswordPolicy;
use App\Domain\UserManagement\StaffUserService;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryAccessTokenDenylist;
use App\Infrastructure\InMemory\InMemoryLoginAttemptRepository;
use App\Infrastructure\InMemory\InMemoryRefreshTokenRepository;
use App\Infrastructure\InMemory\InMemoryRoleAuditRepository;
use App\Infrastructure\InMemory\InMemoryStaffUserRepository;
use App\Infrastructure\InMemory\InMemoryTwoFactorChallengeStore;
use App\Infrastructure\Security\Argon2idPasswordHasher;
use App\Infrastructure\Security\ArrayPasswordDenylist;
use App\Infrastructure\Security\FirebaseJwtSigner;

/**
 * Складання сервісу для тестів ВИКЛЮЧНО на InMemory-реалізаціях:
 * жоден тест не потребує ані MongoDB, ані Redis.
 *
 * Параметри argon2id навмисно послаблені (memory 8 MiB, time 1, threads 1),
 * щоб тести були швидкими; прод-параметри (AUTH-60) перевіряються окремо
 * у тесті хешера.
 */
final class AuthContext
{
    public const string STAFF_SECRET = 'test-staff-secret-0123456789-abcdefghij';
    public const string PARTNER_SECRET = 'test-partner-secret-9876543210-zyxwvuts';

    public readonly FrozenClock $clock;
    public readonly InMemoryStaffUserRepository $users;
    public readonly InMemoryRefreshTokenRepository $refreshTokens;
    public readonly InMemoryLoginAttemptRepository $attempts;
    public readonly InMemoryAccessTokenDenylist $denylist;
    public readonly InMemoryTwoFactorChallengeStore $challenges;
    public readonly InMemoryRoleAuditRepository $audit;
    public readonly Argon2idPasswordHasher $hasher;
    public readonly PasswordPolicy $passwordPolicy;
    public readonly FirebaseJwtSigner $staffSigner;
    public readonly FirebaseJwtSigner $partnerSigner;
    public readonly TokenService $tokens;
    public readonly LoginThrottler $throttler;
    public readonly TotpVerifier $totp;
    public readonly AuthenticationService $authentication;
    public readonly AccessDecider $accessDecider;
    public readonly StaffUserService $userManagement;

    public function __construct(string $now = '2026-08-27T09:00:00+00:00')
    {
        $this->clock = new FrozenClock($now);
        $this->users = new InMemoryStaffUserRepository();
        $this->refreshTokens = new InMemoryRefreshTokenRepository();
        $this->attempts = new InMemoryLoginAttemptRepository();
        $this->denylist = new InMemoryAccessTokenDenylist($this->clock);
        $this->challenges = new InMemoryTwoFactorChallengeStore();
        $this->audit = new InMemoryRoleAuditRepository();

        $this->hasher = new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, threads: 1);
        $this->passwordPolicy = new PasswordPolicy($this->hasher, new ArrayPasswordDenylist());

        // AUTH-02: контури підписуються РІЗНИМИ ключами
        $this->staffSigner = FirebaseJwtSigner::hs256(self::STAFF_SECRET, 'staff-hs-test', $this->clock);
        $this->partnerSigner = FirebaseJwtSigner::hs256(self::PARTNER_SECRET, 'partner-hs-test', $this->clock);

        $this->tokens = new TokenService(
            signer: $this->staffSigner,
            refreshTokens: $this->refreshTokens,
            denylist: $this->denylist,
            clock: $this->clock,
            contour: Contour::Staff,
        );

        $this->throttler = new LoginThrottler($this->attempts, $this->clock);
        $this->totp = new TotpVerifier();

        $this->authentication = new AuthenticationService(
            users: $this->users,
            hasher: $this->hasher,
            passwordPolicy: $this->passwordPolicy,
            tokens: $this->tokens,
            throttler: $this->throttler,
            clock: $this->clock,
            challenges: $this->challenges,
            totp: $this->totp,
        );

        $this->accessDecider = new AccessDecider();

        $this->userManagement = new StaffUserService(
            users: $this->users,
            audit: $this->audit,
            accessDecider: $this->accessDecider,
            hasher: $this->hasher,
            passwordPolicy: $this->passwordPolicy,
            clock: $this->clock,
            tokens: $this->tokens,
        );
    }

    /**
     * @param list<string> $storeIds
     */
    public function createUser(
        string $email,
        Role $role,
        array $storeIds = [],
        string $password = 'Rampa!Staff2026',
        bool $active = true,
    ): StaffUser {
        $user = StaffUser::create(
            email: Email::fromString($email),
            passwordHash: $this->hasher->hash($password),
            role: $role,
            storeIds: $storeIds,
            now: $this->clock->now(),
            fullName: 'Тестовий Користувач',
        );

        if (!$active) {
            $user->deactivate($this->clock->now());
        }

        $this->users->save($user);

        return $user;
    }

    /**
     * Токен ЧУЖОГО контуру: підписаний ключем partner-контуру, з partner-клеймами.
     */
    public function partnerAccessToken(string $subject = 'partner-user-1'): string
    {
        $now = $this->clock->now();

        return $this->partnerSigner->sign([
            'sub' => $subject,
            'role' => Role::SupplierAdmin->value,
            'contour' => Contour::Partner->value,
            'scope' => ['supplierId' => 'sp-01'],
            'sid' => 'sid-partner-1',
            'jti' => 'jti-partner-1',
            'typ' => 'access',
            'iss' => Contour::Partner->issuer(),
            'aud' => Contour::Partner->audience(),
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + 900,
        ]);
    }

    /**
     * Токен, підписаний ПРАВИЛЬНИМ staff-ключем, але з клеймами partner-контуру:
     * перевіряє, що ізоляція тримається не лише на підписі (AUTH-03).
     */
    public function partnerClaimsSignedByStaffKey(): string
    {
        $now = $this->clock->now();

        return $this->staffSigner->sign([
            'sub' => 'partner-user-2',
            'role' => Role::Driver->value,
            'contour' => Contour::Partner->value,
            'sid' => 'sid-partner-2',
            'jti' => 'jti-partner-2',
            'typ' => 'access',
            'iss' => Contour::Partner->issuer(),
            'aud' => Contour::Partner->audience(),
            'iat' => $now->getTimestamp(),
            'exp' => $now->getTimestamp() + 900,
        ]);
    }
}
