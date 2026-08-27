<?php

declare(strict_types=1);

namespace App\Domain\Token;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerRole;

/**
 * Клейми перевіреного access-токена (розділ 3.4).
 *
 * Обовʼязкові: sub, contour, role (однина!), scope (supplierId + driverId для
 * водія), sid, jti, iss, aud, iat, exp.
 */
final readonly class AccessTokenClaims
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $subject,
        public PartnerRole $role,
        public Contour $contour,
        public string $supplierId,
        public ?string $driverId,
        public string $sid,
        public string $jti,
        public string $issuer,
        public string $audience,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
        public array $raw = [],
    ) {
    }
}
