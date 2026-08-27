<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerRole;
use App\Domain\Auth\AccessTokenIntrospector;
use App\Domain\Auth\AuthenticationService;
use App\Domain\Auth\SessionFactory;
use App\Domain\Auth\SessionService;
use App\Domain\Provisioning\CreatePartnerAccount;
use App\Domain\Provisioning\PartnerAccountProvisioner;
use App\Domain\Security\DriverPasswordGenerator;
use App\Domain\Security\LoginNormalizer;
use App\Domain\Security\PhoneNormalizer;
use App\Domain\Security\SecretGenerator;
use App\Domain\Security\SupplierPasswordPolicy;
use App\Domain\Session\LoginThrottle;
use App\Infrastructure\InMemory\FixedClock;
use App\Infrastructure\InMemory\InMemoryAccessTokenDenylist;
use App\Infrastructure\InMemory\InMemoryLoginAttemptRepository;
use App\Infrastructure\InMemory\InMemoryPartnerAccountRepository;
use App\Infrastructure\InMemory\InMemoryRefreshTokenRepository;
use App\Infrastructure\Jwt\JwtKeyPair;
use App\Infrastructure\Jwt\RsaJwtCodec;
use App\Infrastructure\Security\Argon2idPasswordHasher;

/**
 * Складання повного стека identity-partner-service на InMemory-реалізаціях.
 *
 * Тести мають працювати БЕЗ MongoDB і Redis, тому сховища — у памʼяті,
 * годинник — керований (FixedClock), а argon2id працює з полегшеними
 * параметрами (продові 64 MB/3/4 перевіряються окремим тестом хешера).
 */
final class AuthTestEnvironment
{
    /** Ключова пара partner-контуру, спільна на весь процес (генерація RSA дорога). */
    private static ?JwtKeyPair $partnerKeys = null;

    /** Ключова пара «чужого» staff-контуру для перевірки ізоляції (AUTH-02). */
    private static ?JwtKeyPair $staffKeys = null;

    public FixedClock $clock;
    public InMemoryPartnerAccountRepository $accounts;
    public InMemoryRefreshTokenRepository $refreshTokens;
    public InMemoryLoginAttemptRepository $loginAttempts;
    public Argon2idPasswordHasher $passwordHasher;
    public PhoneNormalizer $phones;
    public LoginNormalizer $loginNormalizer;
    public SecretGenerator $secrets;
    public RsaJwtCodec $codec;
    public SessionFactory $sessionFactory;
    public LoginThrottle $throttle;
    public AuthenticationService $authentication;
    public SessionService $sessions;
    public PartnerAccountProvisioner $provisioner;
    public InMemoryAccessTokenDenylist $denylist;
    public AccessTokenIntrospector $introspector;

    public function __construct(string $now = '2026-08-27T09:00:00+00:00')
    {
        $this->clock = new FixedClock($now);
        $this->accounts = new InMemoryPartnerAccountRepository();
        $this->refreshTokens = new InMemoryRefreshTokenRepository();
        $this->loginAttempts = new InMemoryLoginAttemptRepository();
        $this->passwordHasher = self::fastHasher();
        $this->phones = new PhoneNormalizer();
        $this->loginNormalizer = new LoginNormalizer($this->phones);
        $this->secrets = new SecretGenerator();

        $this->codec = new RsaJwtCodec(
            keys: self::partnerKeys(),
            clock: $this->clock,
            secrets: $this->secrets,
            issuer: 'yms-partner',
            audience: 'yms-partner-api',
            contour: Contour::Partner,
        );

        $this->sessionFactory = new SessionFactory(
            refreshTokens: $this->refreshTokens,
            tokenIssuer: $this->codec,
            secrets: $this->secrets,
            clock: $this->clock,
        );

        $this->throttle = new LoginThrottle(
            attempts: $this->loginAttempts,
            clock: $this->clock,
        );

        $this->authentication = new AuthenticationService(
            accounts: $this->accounts,
            passwordHasher: $this->passwordHasher,
            loginNormalizer: $this->loginNormalizer,
            throttle: $this->throttle,
            sessions: $this->sessionFactory,
            clock: $this->clock,
        );

        $this->sessions = new SessionService(
            refreshTokens: $this->refreshTokens,
            accounts: $this->accounts,
            sessions: $this->sessionFactory,
            secrets: $this->secrets,
            clock: $this->clock,
        );

        $this->denylist = new InMemoryAccessTokenDenylist($this->clock);

        // Перевірка токена для api-gateway (GET /internal/v1/auth/verify).
        $this->introspector = new AccessTokenIntrospector(
            tokens: $this->codec,
            denylist: $this->denylist,
            accounts: $this->accounts,
        );

        $this->provisioner = new PartnerAccountProvisioner(
            accounts: $this->accounts,
            refreshTokens: $this->refreshTokens,
            passwordHasher: $this->passwordHasher,
            loginNormalizer: $this->loginNormalizer,
            driverPasswords: new DriverPasswordGenerator(),
            passwordPolicy: new SupplierPasswordPolicy(),
            secrets: $this->secrets,
            clock: $this->clock,
        );
    }

    /** Полегшені параметри argon2id — щоб тести лишались швидкими. */
    public static function fastHasher(): Argon2idPasswordHasher
    {
        return new Argon2idPasswordHasher(memoryCost: 8192, timeCost: 1, parallelism: 1);
    }

    public static function partnerKeys(): JwtKeyPair
    {
        return self::$partnerKeys ??= JwtKeyPair::generate('partner-test-1');
    }

    public static function staffKeys(): JwtKeyPair
    {
        return self::$staffKeys ??= JwtKeyPair::generate('staff-test-1');
    }

    /** Кодек «чужого» staff-контуру: інші ключі, інші iss/aud (AUTH-02, AUTH-03). */
    public function staffCodec(): RsaJwtCodec
    {
        return new RsaJwtCodec(
            keys: self::staffKeys(),
            clock: $this->clock,
            secrets: $this->secrets,
            issuer: 'yms-staff',
            audience: 'yms-staff-api',
            contour: Contour::Staff,
        );
    }

    /** Водій із логіном-телефоном; повертає збережений акаунт. */
    public function givenDriver(
        string $phone = '067 123 45 67',
        string $password = 'Rmp7dK2xTq',
        string $supplierId = 'sp-01',
        ?string $driverProfileId = 'du-99',
        bool $active = true,
    ): PartnerAccount {
        $credentials = $this->provisioner->create(new CreatePartnerAccount(
            login: $phone,
            role: PartnerRole::Driver,
            supplierId: $supplierId,
            passwordPlain: $password,
            driverProfileId: $driverProfileId,
            active: $active,
        ));

        $account = $this->accounts->findById($credentials->profile->accountId);
        \assert($account instanceof PartnerAccount);

        return $account;
    }

    /** Постачальник із логіном-email. */
    public function givenSupplier(
        string $email = 'Sales@Postachalnyk.UA',
        string $password = 'Postach2026',
        PartnerRole $role = PartnerRole::SupplierAdmin,
        string $supplierId = 'sp-01',
        bool $active = true,
    ): PartnerAccount {
        $credentials = $this->provisioner->create(new CreatePartnerAccount(
            login: $email,
            role: $role,
            supplierId: $supplierId,
            passwordPlain: $password,
            active: $active,
        ));

        $account = $this->accounts->findById($credentials->profile->accountId);
        \assert($account instanceof PartnerAccount);

        return $account;
    }
}
