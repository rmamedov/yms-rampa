<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\AnalyticsException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формат помилок HTTP — RFC 7807 application/problem+json з розширеннями
 * code і requestId:
 *
 * {"type":"about:blank","title":"...","status":422,"detail":"...",
 *  "code":"ANALYTICS_INVALID_PERIOD","requestId":"..."}
 */
final readonly class ProblemJsonResponseFactory
{
    public const CONTENT_TYPE = 'application/problem+json';

    public function __construct(private RequestIdProvider $requestIdProvider = new RequestIdProvider())
    {
    }

    public function fromDomainException(AnalyticsException $exception, ?Request $request = null): JsonResponse
    {
        return $this->create(
            status: $exception->httpStatus(),
            title: $exception->title(),
            detail: $exception->getMessage(),
            code: $exception->errorCode(),
            request: $request,
        );
    }

    public function create(
        int $status,
        string $title,
        string $detail,
        string $code,
        ?Request $request = null,
    ): JsonResponse {
        $response = new JsonResponse([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'requestId' => $this->requestIdProvider->forRequest($request),
        ], $status);

        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
