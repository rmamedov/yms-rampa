<?php

declare(strict_types=1);

namespace App\Infrastructure\Jwt;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerRole;
use App\Domain\Clock\Clock;
use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;
use App\Domain\Security\SecretGenerator;
use App\Domain\Token\AccessTokenClaims;
use App\Domain\Token\IssuedAccessToken;
use App\Domain\Token\TokenIssuer;
use App\Domain\Token\TokenVerifier;

/**
 * Випуск і перевірка JWT RS256 партнерського контуру.
 *
 * AUTH-02: власна ключова пара, окрема від staff-контуру.
 * AUTH-03: `iss=yms-partner`, `aud=yms-partner-api`; підпис, iss, aud і термін
 *          дії перевіряються при кожному запиті. Токен staff-контуру не
 *          пройде жодну з цих перевірок → 401 AUTH_TOKEN_INVALID.
 * Розділ 3.4: клейми sub, contour, role (однина), scope (supplierId +
 *          driverId), sid, jti, iat, exp; access TTL — 15 хвилин.
 */
final readonly class RsaJwtCodec implements TokenIssuer, TokenVerifier
{
    public const int DEFAULT_ACCESS_TTL_SECONDS = 900;

    private const string ALGORITHM = 'RS256';

    /** Допуск на розбіжність годинників між сервісами, секунд. */
    private const int CLOCK_SKEW_SECONDS = 30;

    public function __construct(
        private JwtKeyPair $keys,
        private Clock $clock,
        private SecretGenerator $secrets,
        private string $issuer = 'yms-partner',
        private string $audience = 'yms-partner-api',
        private Contour $contour = Contour::Partner,
        private int $accessTtlSeconds = self::DEFAULT_ACCESS_TTL_SECONDS,
    ) {
    }

    public function issueAccessToken(PartnerAccount $account, string $sid): IssuedAccessToken
    {
        $now = $this->clock->now();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->accessTtlSeconds));
        $jti = $this->secrets->newId();

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
            'kid' => $this->keys->keyId,
        ];

        $payload = [
            'sub' => $account->id,
            'role' => $account->role->value,
            'contour' => $this->contour->value,
            'supplierId' => $account->supplierId,
            'driverId' => $account->driverProfileId(),
            'scope' => [
                'supplierId' => $account->supplierId,
                'driverId' => $account->driverProfileId(),
            ],
            'sid' => $sid,
            'jti' => $jti,
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt->getTimestamp(),
        ];

        $signingInput = $this->encodeSegment($header).'.'.$this->encodeSegment($payload);
        $signature = $this->sign($signingInput);

        return new IssuedAccessToken(
            token: $signingInput.'.'.self::base64UrlEncode($signature),
            jti: $jti,
            sid: $sid,
            issuedAt: $now,
            expiresAt: $expiresAt,
        );
    }

    public function verifyAccessToken(string $jwt): AccessTokenClaims
    {
        $parts = explode('.', $jwt);

        if (3 !== \count($parts)) {
            throw new TokenInvalidException('малформований JWT: очікується 3 сегменти');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader, 'header');

        // Жорстко фіксуємо алгоритм: `none` та HS256 (підміна ключа на публічний)
        // мають відхилятись беззастережно.
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new TokenInvalidException('непідтримуваний алгоритм підпису');
        }

        $signature = self::base64UrlDecode($encodedSignature);

        if (null === $signature || !$this->verifySignature($encodedHeader.'.'.$encodedPayload, $signature)) {
            // Сюди ж потрапляє токен, підписаний ключем staff-контуру (AUTH-02).
            throw new TokenInvalidException('підпис не пройшов перевірку ключем partner-контуру');
        }

        $payload = $this->decodeSegment($encodedPayload, 'payload');

        // AUTH-03: iss/aud перевіряються окремо від підпису.
        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new TokenInvalidException('чужий issuer');
        }

        if (!$this->audienceMatches($payload['aud'] ?? null)) {
            throw new TokenInvalidException('чужа audience');
        }

        if (($payload['contour'] ?? null) !== $this->contour->value) {
            throw new TokenInvalidException('токен іншого контуру');
        }

        $expiresAt = $this->timestampClaim($payload, 'exp');
        $issuedAt = $this->timestampClaim($payload, 'iat');
        $now = $this->clock->now();

        if ($expiresAt->getTimestamp() + self::CLOCK_SKEW_SECONDS <= $now->getTimestamp()) {
            throw new TokenExpiredException();
        }

        $role = PartnerRole::tryFrom((string) ($payload['role'] ?? ''));

        if (null === $role) {
            throw new TokenInvalidException('невідома роль у клеймі role');
        }

        foreach (['sub', 'sid', 'jti', 'supplierId'] as $required) {
            if (!isset($payload[$required]) || !\is_string($payload[$required]) || '' === $payload[$required]) {
                throw new TokenInvalidException(\sprintf('відсутній обовʼязковий клейм %s', $required));
            }
        }

        $driverId = $payload['driverId'] ?? null;

        return new AccessTokenClaims(
            subject: (string) $payload['sub'],
            role: $role,
            contour: $this->contour,
            supplierId: (string) $payload['supplierId'],
            driverId: \is_string($driverId) && '' !== $driverId ? $driverId : null,
            sid: (string) $payload['sid'],
            jti: (string) $payload['jti'],
            issuer: $this->issuer,
            audience: $this->audience,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            raw: $payload,
        );
    }

    /** Публічний ключ для JWKS-ендпоїнта (AUTH-64). */
    public function publicKeyPem(): string
    {
        return $this->keys->publicKeyPem;
    }

    public function keyId(): string
    {
        return $this->keys->keyId;
    }

    private function audienceMatches(mixed $aud): bool
    {
        if (\is_string($aud)) {
            return $aud === $this->audience;
        }

        return \is_array($aud) && \in_array($this->audience, $aud, true);
    }

    private function sign(string $signingInput): string
    {
        $privateKey = openssl_pkey_get_private($this->keys->privateKeyPem);

        if (false === $privateKey) {
            throw new \RuntimeException('Не вдалося прочитати приватний ключ partner-контуру.');
        }

        if (!openssl_sign($signingInput, $signature, $privateKey, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Не вдалося підписати JWT.');
        }

        return $signature;
    }

    private function verifySignature(string $signingInput, string $signature): bool
    {
        $publicKey = openssl_pkey_get_public($this->keys->publicKeyPem);

        if (false === $publicKey) {
            throw new \RuntimeException('Не вдалося прочитати публічний ключ partner-контуру.');
        }

        return 1 === openssl_verify($signingInput, $signature, $publicKey, \OPENSSL_ALGO_SHA256);
    }

    /** @param array<string, mixed> $data */
    private function encodeSegment(array $data): string
    {
        return self::base64UrlEncode(json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decodeSegment(string $segment, string $what): array
    {
        $json = self::base64UrlDecode($segment);

        if (null === $json) {
            throw new TokenInvalidException(\sprintf('не вдалося декодувати %s', $what));
        }

        try {
            $decoded = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TokenInvalidException(\sprintf('%s не є коректним JSON', $what));
        }

        if (!\is_array($decoded)) {
            throw new TokenInvalidException(\sprintf('%s не є JSON-обʼєктом', $what));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function timestampClaim(array $payload, string $claim): \DateTimeImmutable
    {
        $value = $payload[$claim] ?? null;

        if (!\is_int($value)) {
            throw new TokenInvalidException(\sprintf('клейм %s відсутній або не є числом', $claim));
        }

        return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
