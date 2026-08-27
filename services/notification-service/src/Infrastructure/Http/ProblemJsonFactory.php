<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формат помилок HTTP — RFC 7807 application/problem+json
 * з розширеннями `code` і `requestId` (конвенція проєкту).
 */
final class ProblemJsonFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /** Заголовок, з якого беремо наскрізний ідентифікатор запиту. */
    public const string REQUEST_ID_HEADER = 'X-Request-Id';

    public function fromDomainException(DomainException $exception, ?Request $request = null): JsonResponse
    {
        return $this->create(
            status: $exception->httpStatus(),
            title: $this->titleFor($exception->httpStatus()),
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
            'requestId' => $this->requestId($request),
        ], $status);

        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }

    private function requestId(?Request $request): string
    {
        $fromHeader = $request?->headers->get(self::REQUEST_ID_HEADER);

        if (null !== $fromHeader && '' !== trim($fromHeader)) {
            return trim($fromHeader);
        }

        return bin2hex(random_bytes(8));
    }

    /** Короткі назви статусів українською. */
    private function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Некоректний запит',
            401 => 'Потрібна автентифікація',
            403 => 'Доступ заборонено',
            404 => 'Не знайдено',
            409 => 'Конфлікт стану',
            422 => 'Помилка валідації',
            501 => 'Не реалізовано',
            503 => 'Сервіс недоступний',
            default => 'Помилка обробки запиту',
        };
    }
}
