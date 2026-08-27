<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Shared\ConflictException;
use App\Domain\Shared\ValidationException;
use App\Infrastructure\Http\ProblemJsonFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формат помилок YMS — RFC 7807 application/problem+json
 * з обов'язковими розширеннями `code` і `requestId`.
 */
#[CoversClass(ProblemJsonFactory::class)]
final class ProblemJsonFactoryTest extends TestCase
{
    public function testValidationExceptionBecomes422ProblemJson(): void
    {
        $factory = new ProblemJsonFactory();
        $request = new Request();
        $request->headers->set('X-Request-Id', 'req-123');

        $response = $factory->fromDomainException(
            new ValidationException('Авто перевищує максимальну масу.', 'VEHICLE_TOO_HEAVY'),
            $request,
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('about:blank', $payload['type']);
        self::assertSame(422, $payload['status']);
        self::assertSame('VEHICLE_TOO_HEAVY', $payload['code']);
        self::assertSame('req-123', $payload['requestId']);
        self::assertSame('Авто перевищує максимальну масу.', $payload['detail']);
    }

    public function testConflictExceptionKeepsStatus409AndDomainCode(): void
    {
        $factory = new ProblemJsonFactory();

        $response = $factory->fromDomainException(
            new ConflictException('Авто з таким номером уже є у вашому довіднику.', 'VEHICLE_PLATE_DUPLICATE'),
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('VEHICLE_PLATE_DUPLICATE', $payload['code']);
    }

    /**
     * Якщо api-gateway не проставив заголовок, requestId усе одно
     * має бути — інакше клієнту нема на що послатися в підтримці.
     */
    public function testRequestIdIsGeneratedWhenHeaderIsAbsent(): void
    {
        $factory = new ProblemJsonFactory();

        $response = $factory->fromDomainException(new ValidationException('Помилка'), new Request());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotSame('', $payload['requestId']);
        self::assertIsString($payload['requestId']);
    }
}
