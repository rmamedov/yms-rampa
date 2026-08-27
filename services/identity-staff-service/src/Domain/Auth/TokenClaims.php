<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Identity\Contour;
use App\Domain\Identity\Role;

/**
 * Клейми токена (розділ 3.4).
 *
 * | Клейм   | Зміст                                                     |
 * |---------|-----------------------------------------------------------|
 * | sub     | id користувача                                            |
 * | contour | staff або partner                                         |
 * | role    | рівно ОДНА канонічна роль RBAC (однина, RBAC-04)          |
 * | scope   | для staff — storeIds                                      |
 * | sid     | id сесії (звʼязок з refresh-ланцюжком)                    |
 * | jti     | унікальний id токена (для denylist)                       |
 * | iat/exp | час видачі та закінчення, UTC                             |
 *
 * AUTH-03: `iss`/`aud` різні для контурів — staff: yms-staff / yms-staff-api.
 */
final readonly class TokenClaims
{
    /**
     * @param list<string> $storeIds
     */
    public function __construct(
        public string $subject,
        public Role $role,
        public Contour $contour,
        public array $storeIds,
        public string $sessionId,
        public string $jti,
        public TokenType $type,
        public string $issuer,
        public string $audience,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->subject,
            // RBAC-04: клейм в однині; масив ролей не використовується
            'role' => $this->role->value,
            'contour' => $this->contour->value,
            'scope' => ['storeIds' => $this->storeIds],
            'storeIds' => $this->storeIds,
            'sid' => $this->sessionId,
            'jti' => $this->jti,
            'typ' => $this->type->value,
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $this->issuedAt->getTimestamp(),
            'exp' => $this->expiresAt->getTimestamp(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidTokenException якщо структура клеймів не відповідає розділу 3.4
     */
    public static function fromArray(array $payload): self
    {
        foreach (['sub', 'role', 'contour', 'sid', 'jti', 'typ', 'iss', 'aud', 'iat', 'exp'] as $required) {
            if (!isset($payload[$required])) {
                throw new InvalidTokenException(\sprintf('відсутній клейм "%s"', $required));
            }
        }

        if (\is_array($payload['role'])) {
            // RBAC-04: масив ролей у токені заборонений
            throw new InvalidTokenException('клейм role має бути рядком (рівно одна роль)');
        }

        $role = Role::tryFrom((string) $payload['role']);
        $contour = Contour::tryFrom((string) $payload['contour']);
        $type = TokenType::tryFrom((string) $payload['typ']);

        if (null === $role || null === $contour || null === $type) {
            throw new InvalidTokenException('невідома роль, контур або тип токена');
        }

        $storeIds = [];
        $rawScope = $payload['scope'] ?? null;
        if (\is_array($rawScope) && isset($rawScope['storeIds']) && \is_array($rawScope['storeIds'])) {
            $storeIds = array_values(array_map(strval(...), $rawScope['storeIds']));
        } elseif (isset($payload['storeIds']) && \is_array($payload['storeIds'])) {
            $storeIds = array_values(array_map(strval(...), $payload['storeIds']));
        }

        return new self(
            subject: (string) $payload['sub'],
            role: $role,
            contour: $contour,
            storeIds: $storeIds,
            sessionId: (string) $payload['sid'],
            jti: (string) $payload['jti'],
            type: $type,
            issuer: (string) $payload['iss'],
            audience: (string) $payload['aud'],
            issuedAt: self::toUtc((int) $payload['iat']),
            expiresAt: self::toUtc((int) $payload['exp']),
        );
    }

    private static function toUtc(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'));
    }
}
