<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Account\PartnerAccountRepository;
use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;
use App\Domain\Token\TokenVerifier;

/**
 * Перевірка access-токена для api-gateway (`GET /internal/v1/auth/verify`).
 *
 * Викликається на КОЖЕН запит до API, тому послідовність вибудувана від
 * найдешевшої перевірки до найдорожчої:
 *  1) підпис, iss/aud, контур, термін дії — офлайн, лише криптографія
 *     (App\Infrastructure\Jwt\RsaJwtCodec, AUTH-02, AUTH-03);
 *  2) denylist `jti` — одне звернення в Redis (AUTH-28, AUTH-32);
 *  3) активність акаунта — одне читання `partner_accounts` за `_id` з
 *     проєкцією лише поля `active` (AUTH-12, AUTH-28); запит покривається
 *     індексом `{_id:1, active:1}`.
 *
 * Будь-яка невдача — TokenInvalidException (401 AUTH_TOKEN_INVALID): шлюзу
 * потрібна бінарна відповідь, а деталізація «протермінований / деактивований /
 * чужий контур» назовні не розкривається (AUTH-53). Технічна причина
 * лишається в technicalReason для логів.
 */
final readonly class AccessTokenIntrospector
{
    public function __construct(
        private TokenVerifier $tokens,
        private AccessTokenDenylist $denylist,
        private PartnerAccountRepository $accounts,
    ) {
    }

    /** @throws TokenInvalidException токен не пройшов бодай одну з перевірок */
    public function introspect(string $accessToken): VerifiedIdentity
    {
        if ('' === trim($accessToken)) {
            throw new TokenInvalidException('порожній access-токен');
        }

        try {
            // Підпис ключем partner-контуру + iss/aud/contour + exp.
            // Токен staff-контуру не проходить жодну з цих перевірок.
            $claims = $this->tokens->verifyAccessToken($accessToken);
        } catch (TokenExpiredException) {
            // Для шлюзу протермінований токен — той самий 401 AUTH_TOKEN_INVALID.
            throw new TokenInvalidException('термін дії access-токена вичерпано');
        }

        if ($this->denylist->isRevoked($claims->jti)) {
            throw new TokenInvalidException('jti відкликано (denylist)');
        }

        $active = $this->accounts->isActive($claims->subject);

        if (null === $active) {
            throw new TokenInvalidException('обліковий запис із клейма sub не існує');
        }

        if (false === $active) {
            throw new TokenInvalidException('обліковий запис деактивовано');
        }

        return VerifiedIdentity::fromClaims($claims);
    }
}
