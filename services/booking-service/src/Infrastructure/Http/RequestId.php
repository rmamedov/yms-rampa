<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Ідентифікатор запиту для наскрізного трейсингу; потрапляє в розширення
 * `requestId` кожної problem+json відповіді.
 */
final readonly class RequestId
{
    public const string HEADER = 'X-Request-Id';

    public static function fromRequest(?Request $request): string
    {
        $header = $request?->headers->get(self::HEADER);

        if (\is_string($header) && '' !== $header) {
            return $header;
        }

        return 'req-'.bin2hex(random_bytes(8));
    }
}
