<?php

declare(strict_types=1);

namespace App\Infrastructure\Mongo;

use App\Domain\Account\UserType;
use App\Domain\Session\RefreshToken;
use App\Domain\Session\RefreshTokenRepository;

/**
 * Колекція `identity_partner.refresh_tokens` (10.6).
 *
 * AUTH-30: зберігається лише SHA-256-хеш токена.
 * Індекси: TTL на `expiresAt` (expireAfterSeconds: 0), `{accountId:1, revokedAt:1}`,
 * unique `{tokenHash:1}`.
 */
final class MongoRefreshTokenRepository extends MongoSupport implements RefreshTokenRepository
{
    public function save(RefreshToken $token): void
    {
        $this->upsert(['_id' => $token->id], ['$set' => self::dehydrate($token)]);
    }

    public function findByHash(string $tokenHash): ?RefreshToken
    {
        $document = $this->findOne(['tokenHash' => $tokenHash]);

        return null === $document ? null : self::hydrate($document);
    }

    public function findBySid(string $sid): array
    {
        return array_map(
            static fn (array $document): RefreshToken => self::hydrate($document),
            $this->find(['sid' => $sid], ['sort' => ['issuedAt' => 1]]),
        );
    }

    public function revokeChain(string $sid, \DateTimeImmutable $at): void
    {
        $this->updateMany(
            ['sid' => $sid, 'revokedAt' => null],
            ['$set' => ['revokedAt' => self::toBson($at)]],
        );
    }

    public function revokeAllForAccount(string $accountId, \DateTimeImmutable $at): void
    {
        $this->updateMany(
            ['accountId' => $accountId, 'revokedAt' => null],
            ['$set' => ['revokedAt' => self::toBson($at)]],
        );
    }

    public function findActiveForAccount(string $accountId, \DateTimeImmutable $now): array
    {
        return array_map(
            static fn (array $document): RefreshToken => self::hydrate($document),
            $this->find([
                'accountId' => $accountId,
                'revokedAt' => null,
                'redeemedAt' => null,
                'expiresAt' => ['$gt' => self::toBson($now)],
            ]),
        );
    }

    protected function collection(): string
    {
        return 'refresh_tokens';
    }

    /** @return array<string, mixed> */
    private static function dehydrate(RefreshToken $token): array
    {
        return [
            'sid' => $token->sid,
            'accountId' => $token->accountId,
            'tokenHash' => $token->tokenHash,
            'userType' => $token->userType->value,
            'issuedAt' => self::toBson($token->issuedAt),
            'expiresAt' => self::toBson($token->expiresAt),
            'ttlSeconds' => $token->ttlSeconds,
            'userAgent' => $token->userAgent,
            'ip' => $token->ip,
            'revokedAt' => self::toBson($token->revokedAt()),
            'redeemedAt' => self::toBson($token->redeemedAt()),
            'schemaVersion' => 1,
        ];
    }

    /** @param array<string, mixed> $document */
    private static function hydrate(array $document): RefreshToken
    {
        return new RefreshToken(
            id: (string) $document['_id'],
            sid: (string) $document['sid'],
            accountId: (string) $document['accountId'],
            tokenHash: (string) $document['tokenHash'],
            userType: UserType::from((string) ($document['userType'] ?? UserType::Supplier->value)),
            issuedAt: self::fromBson($document['issuedAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            expiresAt: self::fromBson($document['expiresAt'] ?? null) ?? new \DateTimeImmutable('@0'),
            ttlSeconds: (int) ($document['ttlSeconds'] ?? 0),
            userAgent: isset($document['userAgent']) && \is_string($document['userAgent']) ? $document['userAgent'] : null,
            ip: isset($document['ip']) && \is_string($document['ip']) ? $document['ip'] : null,
            revokedAt: self::fromBson($document['revokedAt'] ?? null),
            redeemedAt: self::fromBson($document['redeemedAt'] ?? null),
        );
    }
}
