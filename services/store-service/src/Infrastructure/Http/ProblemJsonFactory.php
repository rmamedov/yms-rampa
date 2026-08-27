<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\DomainException;
use App\Domain\Shared\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Формат помилок RFC 7807 (application/problem+json) з розширеннями code і requestId —
 * єдиний формат помилок усіх сервісів YMS «Рампа».
 */
final class ProblemJsonFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';
    public const string REQUEST_ID_HEADER = 'X-Request-Id';

    private function __construct()
    {
    }

    public static function fromDomainException(DomainException $exception, ?Request $request = null): JsonResponse
    {
        return self::build(
            status: $exception->httpStatus(),
            title: $exception->title(),
            detail: $exception->getMessage(),
            code: $exception->errorCode(),
            request: $request,
            extra: [] === $exception->details() ? [] : ['errors' => $exception->details()],
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function build(
        int $status,
        string $title,
        string $detail,
        string $code,
        ?Request $request = null,
        array $extra = [],
    ): JsonResponse {
        $requestId = self::requestId($request);

        $body = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'requestId' => $requestId,
        ] + $extra;

        $response = new JsonResponse($body, $status);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }

    public static function internal(\Throwable $exception, ?Request $request = null, bool $debug = false): JsonResponse
    {
        return self::build(
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
            title: 'Внутрішня помилка сервера',
            detail: $debug ? $exception->getMessage() : 'Сталася неочікувана помилка. Спробуйте пізніше.',
            code: 'INTERNAL_ERROR',
            request: $request,
        );
    }

    public static function requestId(?Request $request): string
    {
        $header = $request?->headers->get(self::REQUEST_ID_HEADER);

        if (\is_string($header) && '' !== trim($header)) {
            return trim($header);
        }

        return Uuid::v4();
    }
}
