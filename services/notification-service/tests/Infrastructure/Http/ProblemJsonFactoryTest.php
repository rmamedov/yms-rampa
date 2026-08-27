<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotImplementedException;
use App\Infrastructure\Http\ProblemJsonFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формат помилок HTTP — RFC 7807 з розширеннями code і requestId.
 */
#[CoversClass(ProblemJsonFactory::class)]
final class ProblemJsonFactoryTest extends TestCase
{
    public function testProblemResponseHasRfc7807Shape(): void
    {
        $request = new Request();
        $request->headers->set('X-Request-Id', 'req-123');

        $response = (new ProblemJsonFactory())->create(
            status: 404,
            title: 'Не знайдено',
            detail: 'Сповіщення «n1» не знайдено.',
            code: 'NOTIFICATION_NOT_FOUND',
            request: $request,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('about:blank', $body['type']);
        self::assertSame('Не знайдено', $body['title']);
        self::assertSame(404, $body['status']);
        self::assertSame('Сповіщення «n1» не знайдено.', $body['detail']);
        self::assertSame('NOTIFICATION_NOT_FOUND', $body['code']);
        self::assertSame('req-123', $body['requestId']);
    }

    public function testRequestIdIsGeneratedWhenHeaderIsAbsent(): void
    {
        $response = (new ProblemJsonFactory())->create(422, 'Помилка валідації', 'Деталі', 'SOME_CODE');

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsString($body['requestId']);
        self::assertNotSame('', $body['requestId']);
    }

    public function testDomainExceptionIsTranslatedToProblem(): void
    {
        $response = (new ProblemJsonFactory())->fromDomainException(
            new DomainException('Сповіщення вже у термінальному статусі.', 'NOTIFICATION_INVALID_TRANSITION', 409),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('Конфлікт стану', $body['title']);
        self::assertSame('NOTIFICATION_INVALID_TRANSITION', $body['code']);
    }

    public function testViberNotImplementedMapsTo501(): void
    {
        $response = (new ProblemJsonFactory())->fromDomainException(
            new NotImplementedException('Канал Viber заплановано на фазу 2.'),
        );

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(501, $response->getStatusCode());
        self::assertSame('NOT_IMPLEMENTED', $body['code']);
        self::assertSame('Не реалізовано', $body['title']);
    }
}
