<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\Contour;
use App\Domain\Account\PartnerRole;
use App\Domain\Token\AccessTokenClaims;

/**
 * Ідентичність, яку api-gateway (nginx `auth_request`) підставляє у службові
 * заголовки запиту до мікросервісів.
 *
 * Мікросервіси (booking-service, store-service…) JWT не перевіряють: вони
 * читають готові заголовки — див. ActorResolver у booking-service. Тому набір
 * полів тут ЖОРСТКО збігається з контрактом обох identity-сервісів.
 *
 * Для partner-контуру:
 *  - supplierId завжди заповнений (у водія теж — це постачальник, до якого він
 *    прикріплений);
 *  - storeIds завжди порожній: магазини у скоуп партнера не входять;
 *  - contour завжди `partner`.
 */
final readonly class VerifiedIdentity
{
    /** @param list<string> $storeIds перелік магазинів у скоупі (для partner — завжди порожній) */
    public function __construct(
        public string $userId,
        public PartnerRole $role,
        public string $supplierId,
        public array $storeIds,
        public Contour $contour,
    ) {
    }

    public static function fromClaims(AccessTokenClaims $claims): self
    {
        return new self(
            userId: $claims->subject,
            role: $claims->role,
            supplierId: $claims->supplierId,
            storeIds: [],
            contour: $claims->contour,
        );
    }

    /** Значення заголовка X-Store-Ids: перелік через кому або порожній рядок. */
    public function storeIdsHeaderValue(): string
    {
        return implode(',', $this->storeIds);
    }
}
