<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Exception\InvalidFilterException;
use App\Infrastructure\Http\ProblemJsonExceptionListener;
use App\Infrastructure\Http\ProblemJsonResponseFactory;
use App\Infrastructure\Http\RequestIdProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Формат помилок RFC 7807 application/problem+json з розширеннями
 * code і requestId.
 */
#[CoversClass(ProblemJsonResponseFactory::class)]
#[CoversClass(ProblemJsonExceptionListener::class)]
final class ProblemJsonResponseFactoryTest extends TestCase
{
    #[Test]
    public function buildsProblemJsonFromDomainException(): void
    {
        $request = Request::create('/api/admin/v1/analytics/kpi');
        $request->headers->set(RequestIdProvider::HEADER, 'req-42');

        $response = (new ProblemJsonResponseFactory())->fromDomainException(
            InvalidFilterException::invalidPeriod('Не вказано період: потрібні параметри from і to (або preset).'),
            $request,
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('about:blank', $payload['type']);
        self::assertSame('Некоректні параметри фільтра', $payload['title']);
        self::assertSame(422, $payload['status']);
        self::assertSame('ANALYTICS_INVALID_PERIOD', $payload['code']);
        self::assertSame('req-42', $payload['requestId']);
        self::assertStringContainsString('Не вказано період', $payload['detail']);
    }

    #[Test]
    public function generatesRequestIdWhenHeaderIsAbsent(): void
    {
        $response = (new ProblemJsonResponseFactory())->create(
            status: 500,
            title: 'Внутрішня помилка сервісу',
            detail: 'Не вдалося обробити запит. Спробуйте пізніше.',
            code: 'INTERNAL_ERROR',
            request: Request::create('/x'),
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotSame('', $payload['requestId']);
        self::assertSame('INTERNAL_ERROR', $payload['code']);
    }

    #[Test]
    public function listenerConvertsDomainExceptionIntoProblemJsonResponse(): void
    {
        $listener = new ProblemJsonExceptionListener(new ProblemJsonResponseFactory());
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/admin/v1/analytics/breakdown'),
            HttpKernelInterface::MAIN_REQUEST,
            InvalidFilterException::invalidDimension('Невідомий розріз «галактика».'),
        );

        $listener($event);
        $response = $event->getResponse();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ANALYTICS_INVALID_DIMENSION', $payload['code']);
    }

    #[Test]
    public function listenerHidesInternalErrorDetails(): void
    {
        $listener = new ProblemJsonExceptionListener(new ProblemJsonResponseFactory());
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/admin/v1/analytics/kpi'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('SQL/Mongo internals'),
        );

        $listener($event);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $event->getResponse()?->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(500, $payload['status']);
        self::assertSame('INTERNAL_ERROR', $payload['code']);
        self::assertStringNotContainsString('Mongo internals', $payload['detail']);
    }
}
