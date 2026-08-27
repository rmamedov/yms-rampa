<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Формування відповідей про помилки у форматі RFC 7807
 * (`application/problem+json`) з обов'язковими розширеннями
 * `code` і `requestId` — єдиний формат помилок усього YMS.
 */
final class ProblemJsonFactory
{
    public const HEADER_REQUEST_ID = 'X-Request-Id';

    public function fromDomainException(DomainException $exception, ?Request $request = null): JsonResponse
    {
        return $this->build(
            status: $exception->httpStatus(),
            title: $exception->title(),
            detail: $exception->getMessage(),
            code: $exception->errorCode(),
            request: $request,
        );
    }

    public function build(
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
            'requestId' => $this->requestId($request),
        ], $status);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    public function badRequest(string $detail, string $code, ?Request $request = null): JsonResponse
    {
        return $this->build(
            status: Response::HTTP_BAD_REQUEST,
            title: 'Некоректний запит',
            detail: $detail,
            code: $code,
            request: $request,
        );
    }

    /**
     * requestId наскрізний: беремо його з заголовка, який проставляє
     * api-gateway, а якщо його немає — генеруємо, щоб клієнт мав
     * на що послатися в підтримці.
     */
    private function requestId(?Request $request): string
    {
        $fromHeader = $request?->headers->get(self::HEADER_REQUEST_ID);

        if (\is_string($fromHeader) && '' !== $fromHeader) {
            return $fromHeader;
        }

        return bin2hex(random_bytes(8));
    }
}
