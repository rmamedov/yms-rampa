<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\AuthException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формування помилок у форматі RFC 7807 `application/problem+json`
 * з проєктними розширеннями `code` і `requestId`.
 *
 * {"type":"about:blank","title":"...","status":401,"detail":"...",
 *  "code":"AUTH_INVALID_CREDENTIALS","requestId":"..."}
 */
final readonly class ProblemJsonFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public function fromAuthException(AuthException $exception, string $requestId): JsonResponse
    {
        return $this->build(
            status: $exception->httpStatus(),
            title: $exception->title(),
            detail: $exception->getMessage(),
            code: $exception->errorCode(),
            requestId: $requestId,
            extensions: $exception->extensions(),
        );
    }

    /** @param array<string, mixed> $extensions */
    public function build(
        int $status,
        string $title,
        string $detail,
        string $code,
        string $requestId,
        array $extensions = [],
    ): JsonResponse {
        $payload = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'requestId' => $requestId,
        ] + $extensions;

        $response = new JsonResponse($payload, $status);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        // AUTH-51: клієнт має знати, коли можна повторити спробу.
        if (isset($extensions['retryAfter']) && \is_int($extensions['retryAfter'])) {
            $response->headers->set('Retry-After', (string) $extensions['retryAfter']);
        }

        return $response;
    }

    /** requestId наскрізний: беремо з заголовка gateway або генеруємо власний. */
    public static function requestId(?Request $request): string
    {
        $fromHeader = $request?->headers->get('X-Request-Id');

        if (\is_string($fromHeader) && '' !== trim($fromHeader)) {
            return mb_substr(trim($fromHeader), 0, 128);
        }

        return bin2hex(random_bytes(8));
    }
}
