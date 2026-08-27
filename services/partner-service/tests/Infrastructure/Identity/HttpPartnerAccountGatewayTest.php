<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Identity;

use App\Domain\Identity\CreateAccountCommand;
use App\Domain\Identity\IdentityUnavailableException;
use App\Domain\Identity\PartnerRole;
use App\Domain\PartnerUser\PartnerUserType;
use App\Domain\Service\DriverService;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use App\Infrastructure\Identity\HttpPartnerAccountGateway;
use App\Infrastructure\InMemory\SequenceIdGenerator;
use App\Infrastructure\Security\SecurePasswordGenerator;
use App\Tests\Support\PartnerTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Шлюз до identity-partner-service (DATA-35).
 *
 * Мережі тут немає: транспорт підмінений MockHttpClient, а тіла відповідей —
 * фікстури РЕАЛЬНОГО контракту сусіда (InternalAccountController +
 * IssuedCredentials::toArray identity-partner-service).
 */
#[CoversClass(HttpPartnerAccountGateway::class)]
#[CoversClass(IdentityUnavailableException::class)]
final class HttpPartnerAccountGatewayTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:8081';
    private const ACCOUNTS_URL = self::BASE_URL.'/internal/v1/partner-accounts';
    private const ACCOUNT_ID = 'ac-0000-4000-8000-000000000001';
    private const SUPPLIER_ID = 'sp-0001';
    private const DRIVER_ID = 'du-0001';
    private const PHONE = '+380671112233';

    // --- створення акаунта (SUP-DRV-03, AUTH-23) ----------------------------

    /**
     * Тіло запиту має збігатися з тим, що читає InternalAccountController:
     * requiredString login/role/supplierId, optionalString passwordPlain і
     * driverProfileId, boolean active.
     */
    public function testCreateAccountSendsBodyExpectedByNeighbour(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($this->createdBody(), ['http_code' => 201]);
        });

        $accountId = $this->gateway($client)->createAccount($this->command('Str0ngPass99'));

        self::assertSame(self::ACCOUNT_ID, $accountId);
        self::assertSame('POST', $captured['method']);
        self::assertSame(self::ACCOUNTS_URL, $captured['url']);
        self::assertStringNotContainsString('/api/', $captured['url']);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $captured['options']['body'], true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame([
            'login' => self::PHONE,
            'role' => 'driver',
            'supplierId' => self::SUPPLIER_ID,
            'passwordPlain' => 'Str0ngPass99',
            'driverProfileId' => self::DRIVER_ID,
            'active' => true,
        ], $body);

        // Ключі контракту сусіда, а не наші доменні синоніми.
        self::assertArrayNotHasKey('password', $body);
        self::assertArrayNotHasKey('mustChangePassword', $body);
    }

    /** Таймаут 3 с має бути і на простій зʼєднання, і на весь виклик. */
    public function testCallIsBoundedByThreeSecondTimeout(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse($this->createdBody(), ['http_code' => 201]);
        });

        $this->gateway($client)->createAccount($this->command());

        self::assertSame(3.0, $captured['timeout']);
        self::assertSame(3.0, $captured['max_duration']);
    }

    /** 409 = unique {login:1}: телефон уже належить іншому водієві (3.3.2). */
    public function testTakenLoginBecomesConflict(): void
    {
        $client = $this->respond([
            'type' => 'about:blank',
            'title' => 'Логін зайнятий',
            'status' => 409,
            'detail' => 'Такий логін уже зареєстровано в партнерському контурі.',
            'code' => 'PARTNER_ACCOUNT_LOGIN_TAKEN',
            'requestId' => 'req-1',
        ], 409);

        try {
            $this->gateway($client)->createAccount($this->command());
            self::fail('Очікувалася ConflictException.');
        } catch (ConflictException $error) {
            self::assertSame(409, $error->httpStatus());
            // Код свій — однаковий з InMemory-реалізацією, щоб клієнт бачив
            // той самий `code` у dev і в проді.
            self::assertSame('ACCOUNT_LOGIN_DUPLICATE', $error->errorCode());
            self::assertStringContainsString(self::PHONE, $error->getMessage());
        }
    }

    /** 422 — сусід відхилив логін або пароль: показуємо його текст користувачу. */
    public function testRejectedDataBecomesValidationError(): void
    {
        $client = $this->respond([
            'title' => 'Некоректні дані',
            'status' => 422,
            'detail' => 'Пароль не відповідає вимогам безпеки.',
            'code' => 'AUTH_WEAK_PASSWORD',
        ], 422);

        try {
            $this->gateway($client)->createAccount($this->command());
            self::fail('Очікувалася ValidationException.');
        } catch (ValidationException $error) {
            self::assertSame(422, $error->httpStatus());
            self::assertSame('ACCOUNT_DATA_REJECTED', $error->errorCode());
            self::assertSame('Пароль не відповідає вимогам безпеки.', $error->getMessage());
        }
    }

    /** 5xx у сусіда — доменна 503 з кодом, а не 500 зі стектрейсом. */
    public function testServerErrorBecomesDomainError(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":500,"code":"INTERNAL_ERROR"}', ['http_code' => 500]));

        $error = $this->captureIdentityFailure($client);

        self::assertSame(503, $error->httpStatus());
        self::assertSame('IDENTITY_UNAVAILABLE', $error->errorCode());
        self::assertStringContainsString('HTTP 500', $error->getMessage());
        self::assertStringContainsString('водія не додано', $error->getMessage());
    }

    /** Таймаут: сусід почав відповідати і замовк. */
    public function testTimeoutBecomesDomainError(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((static function () {
            yield new TransportException('Idle timeout reached for "http://127.0.0.1:8081".');
        })()));

        $error = $this->captureIdentityFailure($client);

        self::assertSame(503, $error->httpStatus());
        self::assertSame('IDENTITY_UNAVAILABLE', $error->errorCode());
        self::assertStringContainsString('тимчасово недоступний', $error->getMessage());
        self::assertInstanceOf(TransportException::class, $error->getPrevious());
    }

    /** Шлюз не піднято взагалі — зʼєднання не встановлюється. */
    public function testUnreachableGatewayBecomesDomainError(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused for "http://127.0.0.1:8081".');
        });

        $error = $this->captureIdentityFailure($client);

        self::assertSame(503, $error->httpStatus());
        self::assertSame('IDENTITY_UNAVAILABLE', $error->errorCode());
    }

    /** 200, але тіло не JSON — повтор не допоможе, це 502. */
    public function testMalformedJsonBecomesBadResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>502 Bad Gateway</html>', ['http_code' => 201]));

        $error = $this->captureIdentityFailure($client);

        self::assertSame(502, $error->httpStatus());
        self::assertSame('IDENTITY_BAD_RESPONSE', $error->errorCode());
        self::assertStringContainsString('некоректний JSON', $error->getMessage());
    }

    /** JSON правильний, але без accountId — далі йти нема з чим. */
    public function testResponseWithoutAccountIdBecomesBadResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('{"login":"+380671112233"}', ['http_code' => 201]));

        $error = $this->captureIdentityFailure($client);

        self::assertSame(502, $error->httpStatus());
        self::assertSame('IDENTITY_BAD_RESPONSE', $error->errorCode());
        self::assertStringContainsString('accountId', $error->getMessage());
    }

    // --- перегенерація пароля (SUP-DRV-04, AUTH-25) -------------------------

    /**
     * Паролем володіє контур ідентичності: назад іде ЙОГО passwordPlain,
     * а не той, що запропонував partner-service.
     */
    public function testRegeneratePasswordReturnsPasswordIssuedByNeighbour(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($this->createdBody(['passwordPlain' => 'NewPass77xy', 'mustChangePassword' => true]));
        });

        $password = $this->gateway($client)->resetPassword(self::ACCOUNT_ID, 'ЩоМиЗапропонували9');

        self::assertSame('NewPass77xy', $password);
        self::assertSame('POST', $captured['method']);
        self::assertSame(self::ACCOUNTS_URL.'/'.self::ACCOUNT_ID.'/password/regenerate', $captured['url']);
        // Маршрут перегенерації тіла не читає — не вигадуємо йому полів.
        self::assertArrayNotHasKey('body', $captured['options'] ?? []);
    }

    public function testRegeneratePasswordForUnknownAccountIsNotFound(): void
    {
        $client = $this->respond(['status' => 404, 'code' => 'PARTNER_ACCOUNT_NOT_FOUND'], 404);

        try {
            $this->gateway($client)->resetPassword(self::ACCOUNT_ID, 'Whatever123');
            self::fail('Очікувалася NotFoundException.');
        } catch (NotFoundException $error) {
            self::assertSame(404, $error->httpStatus());
            self::assertSame('ACCOUNT_NOT_FOUND', $error->errorCode());
        }
    }

    public function testRegeneratedPasswordMissingInResponseIsBadResponse(): void
    {
        $client = new MockHttpClient(new MockResponse($this->createdBody()));

        try {
            $this->gateway($client)->resetPassword(self::ACCOUNT_ID, 'Whatever123');
            self::fail('Очікувалася IdentityUnavailableException.');
        } catch (IdentityUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertStringContainsString('пароль', $error->getMessage());
        }
    }

    // --- активність акаунта (SUP-DRV-05) ------------------------------------

    /** Деактивація = негайне завершення всіх сесій водія (204 без тіла). */
    public function testDeactivationRevokesAllSessions(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->gateway($client)->setAccountActive(self::ACCOUNT_ID, false);

        self::assertSame('DELETE', $captured['method']);
        self::assertSame(self::ACCOUNTS_URL.'/'.self::ACCOUNT_ID.'/sessions', $captured['url']);
    }

    /** Акаунта вже немає — мета «активних сесій нема» досягнута, це не помилка. */
    public function testDeactivationOfMissingAccountIsTolerated(): void
    {
        $client = $this->respond(['status' => 404, 'code' => 'PARTNER_ACCOUNT_NOT_FOUND'], 404);

        $this->gateway($client)->setAccountActive(self::ACCOUNT_ID, false);

        self::assertSame(1, $client->getRequestsCount());
    }

    /** Маршруту «увімкнути один акаунт» контракт сусіда не має — виклику немає. */
    public function testActivationMakesNoCall(): void
    {
        $client = new MockHttpClient([]);

        $this->gateway($client)->setAccountActive(self::ACCOUNT_ID, true);

        self::assertSame(0, $client->getRequestsCount());
    }

    public function testFailedSessionRevocationBecomesDomainError(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 500]));

        try {
            $this->gateway($client)->setAccountActive(self::ACCOUNT_ID, false);
            self::fail('Очікувалася IdentityUnavailableException.');
        } catch (IdentityUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringContainsString('сесії', $error->getMessage());
        }
    }

    // --- блокування постачальника (SUP-02, AUTH-28) -------------------------

    public function testSupplierSuspensionReturnsAffectedAccountCount(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return new MockResponse(json_encode([
                'supplierId' => self::SUPPLIER_ID,
                'deactivatedAccounts' => 7,
            ], \JSON_THROW_ON_ERROR));
        });

        $affected = $this->gateway($client)->setSupplierAccountsActive(self::SUPPLIER_ID, false);

        self::assertSame(7, $affected);
        self::assertSame('POST', $captured['method']);
        self::assertSame(self::ACCOUNTS_URL.'/suppliers/'.self::SUPPLIER_ID.'/suspend', $captured['url']);
    }

    /** Зворотного маршруту в контракті сусіда немає — мережу не смикаємо. */
    public function testSupplierResumeMakesNoCall(): void
    {
        $client = new MockHttpClient([]);

        self::assertSame(0, $this->gateway($client)->setSupplierAccountsActive(self::SUPPLIER_ID, true));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testSuspensionWithoutCounterIsBadResponse(): void
    {
        $client = new MockHttpClient(new MockResponse('{"supplierId":"sp-0001"}'));

        try {
            $this->gateway($client)->setSupplierAccountsActive(self::SUPPLIER_ID, false);
            self::fail('Очікувалася IdentityUnavailableException.');
        } catch (IdentityUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertStringContainsString('deactivatedAccounts', $error->getMessage());
        }
    }

    /** Ідентифікатори приходять ззовні і не мають ламати маршрут. */
    public function testPathSegmentsAreEscaped(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->gateway($client)->setAccountActive('ac/../../etc passwd', false);

        self::assertSame(self::ACCOUNTS_URL.'/ac%2F..%2F..%2Fetc%20passwd/sessions', $captured);
    }

    // --- наскрізний сценарій: «осиротілого» профілю не буває -----------------

    /**
     * Головна гарантія SUP-DRV-03: акаунт створюється ПЕРШИМ, тому падіння
     * контуру ідентичності лишає довідник водіїв недоторканим — водія, який
     * є в partner_users, але не може увійти, не виникає.
     */
    public function testDriverProfileIsNotSavedWhenIdentityIsDown(): void
    {
        $env = new PartnerTestEnvironment();
        $supplier = $env->givenSupplier('ТОВ «Молочна ріка»');

        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused for "http://127.0.0.1:8081".');
        });

        $drivers = new DriverService(
            users: $env->users,
            suppliers: $env->suppliers,
            vehicles: $env->vehicles,
            accounts: $this->gateway($client),
            passwords: new SecurePasswordGenerator(),
            events: $env->events,
            ids: new SequenceIdGenerator('du'),
            clock: $env->clock,
        );

        try {
            $drivers->createDriver(
                supplierId: $supplier->id(),
                phone: '067 111 22 33',
                firstName: 'Микола',
                lastName: 'Гриценко',
            );
            self::fail('Очікувалася IdentityUnavailableException.');
        } catch (IdentityUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringContainsString('водія не додано', $error->getMessage());
        }

        self::assertNull($env->users->findDriverByPhone(self::PHONE));
        self::assertSame([], $env->users->listBySupplier($supplier->id(), PartnerUserType::Driver, true));
        // SMS не піде: подія DriverCreated не публікувалася.
        self::assertSame([], $env->events->ofType('DriverCreated'));
        self::assertSame([], $env->events->all());
    }

    // --- допоміжне ----------------------------------------------------------

    private function gateway(MockHttpClient $client): HttpPartnerAccountGateway
    {
        return new HttpPartnerAccountGateway($client, self::BASE_URL);
    }

    private function command(string $password = 'Str0ngPass99'): CreateAccountCommand
    {
        return new CreateAccountCommand(
            login: self::PHONE,
            password: $password,
            role: PartnerRole::Driver,
            supplierId: self::SUPPLIER_ID,
            driverProfileId: self::DRIVER_ID,
            mustChangePassword: true,
        );
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/problem+json']],
        ));
    }

    /**
     * Тіло IssuedCredentials::toArray() сусіда.
     *
     * @param array<string, mixed> $overrides
     */
    private function createdBody(array $overrides = []): string
    {
        return json_encode($overrides + [
            'accountId' => self::ACCOUNT_ID,
            'login' => self::PHONE,
            'role' => 'driver',
            'contour' => 'partner',
            'supplierId' => self::SUPPLIER_ID,
            'driverId' => self::DRIVER_ID,
            'mustChangePassword' => false,
            'passwordGenerated' => false,
        ], \JSON_THROW_ON_ERROR);
    }

    private function captureIdentityFailure(MockHttpClient $client): IdentityUnavailableException
    {
        try {
            $this->gateway($client)->createAccount($this->command());
        } catch (IdentityUnavailableException $error) {
            return $error;
        }

        self::fail('Очікувалася IdentityUnavailableException.');
    }
}
