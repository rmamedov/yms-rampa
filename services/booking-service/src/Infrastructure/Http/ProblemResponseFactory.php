<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\DomainProblem;
use App\Domain\Slot\DateOutOfHorizonException;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Формат помилок RFC 7807 (application/problem+json) з розширеннями
 * `code` і `requestId`.
 *
 * Приклад:
 * {"type":"about:blank","title":"...","status":422,"detail":"...",
 *  "code":"VEHICLE_TOO_HEAVY","requestId":"..."}
 */
final readonly class ProblemResponseFactory
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public function fromThrowable(Throwable $error, string $requestId): JsonResponse
    {
        [$status, $code, $detail, $extensions] = $this->describe($error);

        return $this->build($status, $code, $detail, $requestId, $extensions);
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public function build(
        int $status,
        string $code,
        string $detail,
        string $requestId,
        array $extensions = [],
    ): JsonResponse {
        $payload = array_merge([
            'type' => 'about:blank',
            'title' => self::titleFor($status),
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'requestId' => $requestId,
        ], $extensions);

        $response = new JsonResponse($payload, $status);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }

    /**
     * @return array{int, string, string, array<string, mixed>}
     */
    private function describe(Throwable $error): array
    {
        if ($error instanceof DomainProblem) {
            return [
                $error->httpStatus(),
                $error->errorCode(),
                $error->getMessage(),
                $error->problemExtensions(),
            ];
        }

        // GRID-03: наявний доменний виняток слотового движка.
        if ($error instanceof DateOutOfHorizonException) {
            return [
                422,
                DateOutOfHorizonException::ERROR_CODE,
                $error->getMessage(),
                ['horizonDays' => $error->horizonDays],
            ];
        }

        if ($error instanceof InvalidArgumentException || $error instanceof JsonException) {
            return [422, 'VALIDATION_FAILED', $error->getMessage(), []];
        }

        if ($error instanceof HttpExceptionInterface) {
            return [
                $error->getStatusCode(),
                404 === $error->getStatusCode() ? 'NOT_FOUND' : 'HTTP_ERROR',
                $error->getMessage(),
                [],
            ];
        }

        return [500, 'INTERNAL_ERROR', 'Внутрішня помилка сервісу', []];
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Некоректний запит',
            403 => 'Доступ заборонено',
            404 => 'Не знайдено',
            409 => 'Конфлікт',
            422 => 'Не пройдено валідацію',
            default => $status >= 500 ? 'Внутрішня помилка' : 'Помилка запиту',
        };
    }
}
