<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DriverAuthController;
use App\Controller\SupplierAuthController;
use App\Domain\Exception\AccountDisabledException;
use App\Domain\Exception\InvalidCredentialsException;
use App\Domain\Exception\ValidationException;
use App\Infrastructure\Http\AuthExceptionListener;
use App\Infrastructure\Http\ProblemJsonFactory;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * HTTP-контракт партнерського контуру (AUTH-40, DRV-06, DRV-09).
 *
 * Контролери тестуються без завантаження ядра: важливий саме контракт
 * «запит → відповідь», а не інфраструктура Symfony.
 */
final class AuthControllerTest extends TestCase
{
    private const string DRIVER_PASSWORD = 'Rmp7dK2xTq';

    private AuthTestEnvironment $env;
    private DriverAuthController $driverController;
    private SupplierAuthController $supplierController;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
        $this->driverController = new DriverAuthController($this->env->authentication, $this->env->sessions);
        $this->supplierController = new SupplierAuthController($this->env->authentication, $this->env->sessions);
    }

    public function testDriverLoginReturnsTokensAndProfile(): void
    {
        $this->env->givenDriver();

        $response = $this->driverController->login($this->jsonRequest([
            'phone' => '067 123 45 67',
            'password' => self::DRIVER_PASSWORD,
        ]));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertSame('Bearer', $payload['tokenType']);
        self::assertSame(900, $payload['expiresIn']);
        self::assertSame('driver', $payload['profile']['role']);
        self::assertSame('partner', $payload['profile']['contour']);
        self::assertSame('+380671234567', $payload['profile']['login']);
    }

    public function testDriverLoginHonoursRememberMeFlag(): void
    {
        // AUTH-27: без прапорця — 30 днів.
        $this->env->givenDriver();

        $response = $this->driverController->login($this->jsonRequest([
            'phone' => '0671234567',
            'password' => self::DRIVER_PASSWORD,
            'rememberMe' => false,
        ]));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        $expiresAt = new \DateTimeImmutable((string) $payload['refreshExpiresAt']);
        self::assertSame(30 * 86400, $expiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp());
    }

    public function testDriverLoginWithoutPhoneFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->driverController->login($this->jsonRequest(['password' => self::DRIVER_PASSWORD]));
    }

    public function testInvalidCredentialsAreRenderedAsProblemJson(): void
    {
        $this->env->givenDriver();
        $request = $this->jsonRequest(['phone' => '0671234567', 'password' => 'Хибний123']);
        $request->headers->set('X-Request-Id', 'gw-777');

        $response = $this->handleThroughListener(
            $request,
            fn (): Response => $this->driverController->login($request),
        );
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertIsArray($payload);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $payload['code']);
        self::assertSame('gw-777', $payload['requestId']);
    }

    public function testDisabledDriverGetsProblemJsonWith403(): void
    {
        $this->env->givenDriver(active: false);
        $request = $this->jsonRequest(['phone' => '0671234567', 'password' => self::DRIVER_PASSWORD]);

        $response = $this->handleThroughListener(
            $request,
            fn (): Response => $this->driverController->login($request),
        );
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(403, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertSame('AUTH_ACCOUNT_DISABLED', $payload['code']);
    }

    public function testDriverRefreshAndLogoutRoundTrip(): void
    {
        $this->env->givenDriver();

        $loginPayload = json_decode((string) $this->driverController->login($this->jsonRequest([
            'phone' => '0671234567',
            'password' => self::DRIVER_PASSWORD,
        ]))->getContent(), true);
        self::assertIsArray($loginPayload);

        $refreshResponse = $this->driverController->refresh($this->jsonRequest([
            'refreshToken' => $loginPayload['refreshToken'],
        ]));
        $refreshPayload = json_decode((string) $refreshResponse->getContent(), true);
        self::assertIsArray($refreshPayload);
        self::assertSame(200, $refreshResponse->getStatusCode());
        self::assertNotSame($loginPayload['refreshToken'], $refreshPayload['refreshToken']);

        $logoutResponse = $this->driverController->logout($this->jsonRequest([
            'refreshToken' => $refreshPayload['refreshToken'],
        ]));

        self::assertSame(204, $logoutResponse->getStatusCode());
    }

    public function testSupplierLoginUsesLoginFieldAndThirtyDayRefresh(): void
    {
        $this->env->givenSupplier(password: 'Postach2026');

        $response = $this->supplierController->login($this->jsonRequest([
            'login' => 'Sales@Postachalnyk.UA',
            'password' => 'Postach2026',
        ]));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('supplier_admin', $payload['profile']['role']);
        $expiresAt = new \DateTimeImmutable((string) $payload['refreshExpiresAt']);
        self::assertSame(30 * 86400, $expiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp());
    }

    public function testSupplierEndpointRefusesDriverAccount(): void
    {
        // DRV-10 навпаки: водій не входить у supplier-web.
        $this->env->givenDriver();

        $this->expectException(InvalidCredentialsException::class);
        $this->supplierController->login($this->jsonRequest([
            'login' => '+380671234567',
            'password' => self::DRIVER_PASSWORD,
        ]));
    }

    public function testUnexpectedExceptionBecomesGenericProblemJson(): void
    {
        // AUTH-53: технічні деталі не витікають у відповідь.
        $request = $this->jsonRequest([]);
        $response = $this->handleThroughListener(
            $request,
            static fn (): Response => throw new \RuntimeException('SQL-подібна технічна деталь'),
        );
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(500, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertSame('INTERNAL_ERROR', $payload['code']);
        self::assertStringNotContainsString('SQL-подібна', (string) $response->getContent());
    }

    public function testDisabledAccountResponseNeverLeaksPassword(): void
    {
        // AUTH-61: пароль не потрапляє у відповідь і не логується.
        $this->env->givenDriver(active: false);
        $request = $this->jsonRequest(['phone' => '0671234567', 'password' => self::DRIVER_PASSWORD]);

        $response = $this->handleThroughListener(
            $request,
            fn (): Response => $this->driverController->login($request),
        );

        self::assertStringNotContainsString(self::DRIVER_PASSWORD, (string) $response->getContent());
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            uri: '/api/driver/v1/auth/login',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($body),
        );
    }

    /** Проганяє виняток контролера через слухач, як це робить HttpKernel. */
    private function handleThroughListener(Request $request, callable $controller): Response
    {
        try {
            return $controller();
        } catch (\Throwable $exception) {
            $listener = new AuthExceptionListener(new ProblemJsonFactory());
            $event = new ExceptionEvent(
                new class implements HttpKernelInterface {
                    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                    {
                        return new Response();
                    }
                },
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                $exception,
            );

            $listener->onKernelException($event);
            $response = $event->getResponse();

            self::assertInstanceOf(Response::class, $response);

            return $response;
        }
    }
}
