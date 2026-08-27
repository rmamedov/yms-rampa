<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\InternalAuthVerifyController;
use App\Domain\Account\PartnerAccount;
use App\Domain\Account\PartnerRole;
use App\Infrastructure\Http\AuthExceptionListener;
use App\Infrastructure\Http\ProblemJsonFactory;
use App\Tests\Support\AuthTestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Контракт `GET /internal/v1/auth/verify` — перевірка токена для api-gateway.
 *
 * Шлюз (nginx `auth_request`) очікує рівно два результати:
 *  - 204 без тіла + заголовки X-User-Id / X-User-Role / X-Supplier-Id /
 *    X-Store-Ids / X-Contour;
 *  - 401 application/problem+json з code=AUTH_TOKEN_INVALID на будь-яку
 *    невдачу (підпис, exp, чужий контур, denylist, деактивований акаунт,
 *    відсутній чи покручений Authorization).
 *
 * Тести перевіряють і статус, і КОЖЕН заголовок: від цього залежить
 * ActorResolver у booking-service та інших мікросервісах.
 */
final class InternalAuthVerifyControllerTest extends TestCase
{
    private AuthTestEnvironment $env;
    private InternalAuthVerifyController $controller;

    protected function setUp(): void
    {
        $this->env = new AuthTestEnvironment();
        $this->controller = new InternalAuthVerifyController($this->env->introspector);
    }

    public function testSupplierAdminTokenYieldsIdentityHeaders(): void
    {
        $account = $this->env->givenSupplier(role: PartnerRole::SupplierAdmin, supplierId: 'sp-77');

        $response = $this->controller->__invoke($this->verifyRequest($this->accessTokenFor($account)));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame($account->id, $response->headers->get('X-User-Id'));
        self::assertSame('supplier_admin', $response->headers->get('X-User-Role'));
        self::assertSame('sp-77', $response->headers->get('X-Supplier-Id'));
        self::assertSame('', $response->headers->get('X-Store-Ids'));
        self::assertSame('partner', $response->headers->get('X-Contour'));
    }

    public function testSupplierOperatorTokenYieldsIdentityHeaders(): void
    {
        $account = $this->env->givenSupplier(
            email: 'operator@postachalnyk.ua',
            role: PartnerRole::SupplierOperator,
            supplierId: 'sp-88',
        );

        $response = $this->controller->__invoke($this->verifyRequest($this->accessTokenFor($account)));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame($account->id, $response->headers->get('X-User-Id'));
        self::assertSame('supplier_operator', $response->headers->get('X-User-Role'));
        self::assertSame('sp-88', $response->headers->get('X-Supplier-Id'));
        self::assertSame('', $response->headers->get('X-Store-Ids'));
        self::assertSame('partner', $response->headers->get('X-Contour'));
    }

    public function testDriverTokenCarriesSupplierIdAndEmptyStores(): void
    {
        // Для водія X-Supplier-Id теж заповнений: це постачальник, до якого
        // прикріплений водій. Магазини у скоуп partner-контуру не входять.
        $account = $this->env->givenDriver(supplierId: 'sp-99');

        $response = $this->controller->__invoke($this->verifyRequest($this->accessTokenFor($account)));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame($account->id, $response->headers->get('X-User-Id'));
        self::assertSame('driver', $response->headers->get('X-User-Role'));
        self::assertSame('sp-99', $response->headers->get('X-Supplier-Id'));
        self::assertSame('', $response->headers->get('X-Store-Ids'));
        self::assertSame('partner', $response->headers->get('X-Contour'));
    }

    public function testSchemeNameIsCaseInsensitive(): void
    {
        // RFC 7235: назва схеми регістронезалежна.
        $account = $this->env->givenDriver();
        $request = Request::create('/internal/v1/auth/verify', 'GET');
        $request->headers->set('Authorization', 'bearer '.$this->accessTokenFor($account));

        $response = $this->controller->__invoke($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame($account->id, $response->headers->get('X-User-Id'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $account = $this->env->givenDriver();
        $token = $this->accessTokenFor($account);

        // Access живе 15 хв (+30 с допуску на розбіжність годинників).
        $this->env->clock->advance('+16 minutes');

        $this->assertRejected($token);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $account = $this->env->givenSupplier();
        $token = $this->accessTokenFor($account);
        [$header, $payload, $signature] = explode('.', $token);

        // Псуємо рівно один символ підпису, зберігаючи довжину й алфавіт base64url.
        $lastChar = substr($signature, -1);
        $tampered = substr($signature, 0, -1).('X' === $lastChar ? 'Y' : 'X');

        self::assertNotSame($signature, $tampered);
        $this->assertRejected($header.'.'.$payload.'.'.$tampered);
    }

    public function testForeignContourTokenIsRejected(): void
    {
        // AUTH-02/AUTH-03: staff-токен підписаний іншим ключем і має інші
        // iss/aud та contour — жодна перевірка не проходить.
        $account = $this->env->givenSupplier();
        $staffToken = $this->env->staffCodec()->issueAccessToken($account, 'sid-staff')->token;

        $this->assertRejected($staffToken);
    }

    public function testRevokedJtiIsRejected(): void
    {
        $account = $this->env->givenDriver();
        $issued = $this->env->codec->issueAccessToken($account, 'sid-1');

        // Токен ще не прострочений, але його jti занесено в denylist (AUTH-28).
        $this->env->denylist->revoke($issued->jti, $issued->expiresAt);

        $this->assertRejected($issued->token);
    }

    public function testDeactivatedAccountIsRejected(): void
    {
        $account = $this->env->givenDriver();
        $token = $this->accessTokenFor($account);

        $account->deactivate($this->env->clock->now());
        $this->env->accounts->save($account);

        $this->assertRejected($token);
    }

    public function testTokenOfUnknownAccountIsRejected(): void
    {
        $account = $this->env->givenSupplier();
        $token = $this->accessTokenFor($account);

        $this->env->accounts->clear();

        $this->assertRejected($token);
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $response = $this->handleThroughListener(
            $request = Request::create('/internal/v1/auth/verify', 'GET'),
            fn (): Response => $this->controller->__invoke($request),
        );

        $this->assertProblemJson($response);
    }

    #[DataProvider('malformedAuthorizationHeaders')]
    public function testMalformedAuthorizationHeaderIsRejected(string $headerValue): void
    {
        $account = $this->env->givenSupplier();
        $request = Request::create('/internal/v1/auth/verify', 'GET');
        $request->headers->set('Authorization', str_replace('{token}', $this->accessTokenFor($account), $headerValue));

        $response = $this->handleThroughListener($request, fn (): Response => $this->controller->__invoke($request));

        $this->assertProblemJson($response);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAuthorizationHeaders(): iterable
    {
        yield 'порожній заголовок' => ['   '];
        yield 'без схеми' => ['{token}'];
        yield 'чужа схема' => ['Basic {token}'];
        yield 'схема без токена' => ['Bearer'];
        yield 'схема з порожнім токеном' => ['Bearer   '];
        yield 'два токени' => ['Bearer {token} {token}'];
    }

    /** Невалідний токен → 401 AUTH_TOKEN_INVALID і жодного заголовка ідентичності. */
    private function assertRejected(string $token): void
    {
        $request = $this->verifyRequest($token);
        $response = $this->handleThroughListener($request, fn (): Response => $this->controller->__invoke($request));

        $this->assertProblemJson($response);
    }

    private function assertProblemJson(Response $response): void
    {
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertIsArray($payload);
        self::assertSame('AUTH_TOKEN_INVALID', $payload['code']);
        self::assertSame(401, $payload['status']);

        // Шлюз не має чого підставити в запит — жодного службового заголовка.
        foreach (['X-User-Id', 'X-User-Role', 'X-Supplier-Id', 'X-Store-Ids', 'X-Contour'] as $header) {
            self::assertFalse($response->headers->has($header), \sprintf('Заголовок %s не має бути у відповіді 401.', $header));
        }
    }

    private function accessTokenFor(PartnerAccount $account, string $sid = 'sid-1'): string
    {
        return $this->env->codec->issueAccessToken($account, $sid)->token;
    }

    private function verifyRequest(string $token): Request
    {
        $request = Request::create('/internal/v1/auth/verify', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
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
