<?php

declare(strict_types=1);

namespace App\Domain\Token;

use App\Domain\Exception\TokenExpiredException;
use App\Domain\Exception\TokenInvalidException;

/**
 * Перевірка access-токенів (AUTH-03: підпис + iss + aud + термін дії
 * перевіряються при кожному запиті).
 */
interface TokenVerifier
{
    /**
     * @throws TokenInvalidException підпис/структура/iss/aud не пройшли перевірку
     * @throws TokenExpiredException токен прострочено
     */
    public function verifyAccessToken(string $jwt): AccessTokenClaims;
}
