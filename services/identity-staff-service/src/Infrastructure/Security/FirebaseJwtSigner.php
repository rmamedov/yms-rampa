<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\Exception\TokenExpiredException;
use App\Domain\Auth\TokenSigner;
use App\Domain\Shared\Clock;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Підпис JWT через firebase/php-jwt.
 *
 * AUTH-02/AUTH-64: ключі staff-контуру ізольовані від partner-контуру.
 * Підтримуються RS256 (прод, асиметрична пара, публічний ключ роздається
 * через JWKS) і HS256 (локальна розробка та тести). Токен, підписаний
 * ключем іншого контуру, не проходить перевірку підпису → 401 AUTH_TOKEN_INVALID.
 *
 * `kid` у заголовку підтримує ротацію ключів з періодом перекриття (AUTH-64):
 * перевірка приймає будь-який із зареєстрованих публічних ключів.
 */
final class FirebaseJwtSigner implements TokenSigner
{
    /**
     * @param non-empty-string             $algorithm      RS256 або HS256
     * @param array<string, string>        $verificationKeys ключі перевірки за kid (ротація, AUTH-64)
     */
    private function __construct(
        private readonly string $algorithm,
        private readonly string $signingKey,
        private readonly array $verificationKeys,
        private readonly string $keyId,
        private readonly ?Clock $clock = null,
    ) {
        if (!\in_array($algorithm, ['RS256', 'HS256'], true)) {
            throw new \InvalidArgumentException(\sprintf('Непідтримуваний алгоритм підпису "%s".', $algorithm));
        }
    }

    /**
     * Симетричний варіант: секрет staff-контуру ОБОВʼЯЗКОВО відрізняється
     * від секрета partner-контуру (AUTH-02).
     */
    public static function hs256(string $secret, string $keyId = 'staff-hs-1', ?Clock $clock = null): self
    {
        if (\strlen($secret) < 32) {
            throw new \InvalidArgumentException('Секрет HS256 має бути не коротшим за 32 байти.');
        }

        return new self('HS256', $secret, [$keyId => $secret], $keyId, $clock);
    }

    /**
     * Асиметричний варіант (AUTH-02: RS256, окрема ключова пара контуру).
     *
     * @param array<string, string> $extraPublicKeys додаткові публічні ключі для періоду ротації
     */
    public static function rs256(
        string $privateKeyPem,
        string $publicKeyPem,
        string $keyId = 'staff-rs-1',
        array $extraPublicKeys = [],
        ?Clock $clock = null,
    ): self {
        return new self('RS256', $privateKeyPem, [$keyId => $publicKeyPem] + $extraPublicKeys, $keyId, $clock);
    }

    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->signingKey, $this->algorithm, $this->keyId);
    }

    public function verify(string $token): array
    {
        // Детермінований час перевірки exp/iat (важливо для тестів і для DATA-01)
        JWT::$timestamp = $this->clock?->now()->getTimestamp();

        $keys = [];
        foreach ($this->verificationKeys as $kid => $key) {
            $keys[$kid] = new Key($key, $this->algorithm);
        }

        try {
            $decoded = JWT::decode($token, $keys);
        } catch (ExpiredException $e) {
            throw new TokenExpiredException();
        } catch (SignatureInvalidException $e) {
            // Найчастіша причина — токен ЧУЖОГО контуру (AUTH-02)
            throw new InvalidTokenException('невалідний підпис: '.$e->getMessage());
        } catch (BeforeValidException $e) {
            throw new InvalidTokenException('токен ще не дійсний: '.$e->getMessage());
        } catch (\UnexpectedValueException|\DomainException $e) {
            throw new InvalidTokenException('некоректний токен: '.$e->getMessage());
        } finally {
            JWT::$timestamp = null;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode(json_encode($decoded, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }
}
