<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * requestId для наскрізного трасування: береться із заголовка запиту
 * (api-gateway проставляє X-Request-Id) або генерується.
 */
final readonly class RequestIdProvider
{
    public const HEADER = 'X-Request-Id';

    public function forRequest(?Request $request): string
    {
        $fromHeader = $request?->headers->get(self::HEADER);
        if (is_string($fromHeader) && $fromHeader !== '') {
            return $fromHeader;
        }

        return bin2hex(random_bytes(8));
    }
}
