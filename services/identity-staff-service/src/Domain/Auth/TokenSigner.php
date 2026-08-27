<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exception\InvalidTokenException;
use App\Domain\Auth\Exception\TokenExpiredException;

/**
 * Підпис і перевірка JWT (AUTH-02, AUTH-64).
 *
 * Ключі staff-контуру ізольовані від partner-контуру: токен, підписаний
 * ключем іншого контуру, ОБОВʼЯЗКОВО не проходить verify() і завершується
 * InvalidTokenException (401 AUTH_TOKEN_INVALID).
 */
interface TokenSigner
{
    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string;

    /**
     * @return array<string, mixed>
     *
     * @throws TokenExpiredException токен прострочено (401 AUTH_TOKEN_EXPIRED)
     * @throws InvalidTokenException підпис/формат/алгоритм невалідні (401 AUTH_TOKEN_INVALID)
     */
    public function verify(string $token): array;

    public function algorithm(): string;
}
