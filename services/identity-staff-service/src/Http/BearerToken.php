<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Auth\Exception\InvalidTokenException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір заголовка `Authorization: Bearer <token>` (RFC 6750, §2.1).
 *
 * AUTH-02: відсутній, порожній або некоректно сформований заголовок —
 * це помилка автентифікації 401 AUTH_TOKEN_INVALID, а не 400: жоден запит
 * без валідного токена не має відрізнятися для клієнта від запиту з чужим.
 */
final class BearerToken
{
    public static function fromRequest(Request $request): string
    {
        $header = trim((string) $request->headers->get('Authorization', ''));

        if (1 !== preg_match('/^Bearer\s+(\S.*)$/i', $header, $matches)) {
            throw new InvalidTokenException('відсутній або некоректний заголовок Authorization: Bearer');
        }

        return trim($matches[1]);
    }
}
