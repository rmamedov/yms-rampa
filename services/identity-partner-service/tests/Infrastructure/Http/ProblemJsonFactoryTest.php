<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Exception\AccountLockedException;
use App\Domain\Exception\InvalidCredentialsException;
use App\Domain\Exception\WeakPasswordException;
use App\Infrastructure\Http\ProblemJsonFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Формат помилок проєкту: RFC 7807 application/problem+json з розширеннями
 * `code` і `requestId` (розділ 3.7 — коди й тексти).
 */
final class ProblemJsonFactoryTest extends TestCase
{
    public function testInvalidCredentialsBecomesProblemJson(): void
    {
        $response = (new ProblemJsonFactory())->fromAuthException(new InvalidCredentialsException(), 'req-42');
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertSame('about:blank', $payload['type']);
        self::assertSame(401, $payload['status']);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $payload['code']);
        self::assertSame('req-42', $payload['requestId']);
        self::assertSame('Невірний логін або пароль.', $payload['detail']);
    }

    public function testLockedAccountCarriesRetryAfterHeader(): void
    {
        // AUTH-50 / AUTH-51.
        $response = (new ProblemJsonFactory())->fromAuthException(new AccountLockedException(742), 'req-43');
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame(423, $response->getStatusCode());
        self::assertSame('AUTH_ACCOUNT_LOCKED', $payload['code']);
        self::assertSame(742, $payload['retryAfter']);
        self::assertSame('742', $response->headers->get('Retry-After'));
    }

    public function testWeakPasswordListsViolations(): void
    {
        $response = (new ProblemJsonFactory())->fromAuthException(
            new WeakPasswordException(['Пароль має містити щонайменше 10 символів.']),
            'req-44',
        );
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('AUTH_WEAK_PASSWORD', $payload['code']);
        self::assertSame(['Пароль має містити щонайменше 10 символів.'], $payload['violations']);
    }

    public function testRequestIdIsTakenFromGatewayHeader(): void
    {
        $request = Request::create('/api/driver/v1/auth/login', 'POST');
        $request->headers->set('X-Request-Id', 'gw-0001');

        self::assertSame('gw-0001', ProblemJsonFactory::requestId($request));
    }

    public function testRequestIdIsGeneratedWhenHeaderIsMissing(): void
    {
        $request = Request::create('/api/driver/v1/auth/login', 'POST');

        self::assertNotSame('', ProblemJsonFactory::requestId($request));
        self::assertNotSame(ProblemJsonFactory::requestId($request), ProblemJsonFactory::requestId($request));
    }
}
