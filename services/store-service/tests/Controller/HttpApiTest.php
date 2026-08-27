<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Infrastructure\Http\ProblemJsonFactory;
use App\Kernel;
use App\Tests\Support\BranchFactory;
use App\Tests\Support\IdentityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP-контур store-service: схема URL /api/{admin|supplier}/v1/... і формат
 * помилок RFC 7807 з розширеннями code і requestId.
 */
#[CoversClass(ProblemJsonFactory::class)]
#[CoversClass(\App\Infrastructure\Http\DomainExceptionListener::class)]
final class HttpApiTest extends TestCase
{
    private Kernel $kernel;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $this->container = $container;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testStoreListReturnsServerSidePagination(): void
    {
        $this->seedBranch();

        $response = $this->request('GET', '/api/admin/v1/stores?perPage=20&page=1');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame(1, $body['total']);
        self::assertSame(20, $body['perPage']);
        self::assertSame('1998', $body['items'][0]['externalId']);
        self::assertFalse($body['items'][0]['configured']);
    }

    public function testStoreCardExposesReadOnlyMcpBlock(): void
    {
        $this->seedBranch();

        $body = $this->json($this->request('GET', '/api/admin/v1/stores/'.BranchFactory::KYIV_ID));

        self::assertSame('Київ', $body['mcpData']['city']);
        self::assertNull($body['displayName']);
        self::assertSame('not_configured', $body['ymsStatus']);
    }

    /** Формат помилок: application/problem+json з code і requestId. */
    public function testUnknownStoreReturnsProblemJson(): void
    {
        $response = $this->request('GET', '/api/admin/v1/stores/11111111-1111-4111-8111-111111111111');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = $this->json($response);

        self::assertSame('about:blank', $body['type']);
        self::assertSame(404, $body['status']);
        self::assertSame('STORE_NOT_FOUND', $body['code']);
        self::assertNotSame('', $body['requestId']);
    }

    /** requestId береться з X-Request-Id, якщо його передав api-gateway. */
    public function testRequestIdIsPropagatedFromHeader(): void
    {
        $response = $this->request(
            'GET',
            '/api/admin/v1/stores/11111111-1111-4111-8111-111111111111',
            headers: ['HTTP_X_REQUEST_ID' => 'gateway-req-42'],
        );

        self::assertSame('gateway-req-42', $this->json($response)['requestId']);
        self::assertSame('gateway-req-42', $response->headers->get('X-Request-Id'));
    }

    /** STC-03: активація без конфігурації → 422 STORE_NOT_CONFIGURED. */
    public function testActivationWithoutConfigurationReturns422(): void
    {
        $this->seedBranch();

        $response = $this->request(
            'PATCH',
            '/api/admin/v1/stores/'.BranchFactory::KYIV_ID,
            content: json_encode(['ymsStatus' => 'active'], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame('STORE_NOT_CONFIGURED', $body['code']);
        self::assertArrayHasKey('errors', $body);
    }

    /** STC-20: недопустимий розмір слоту → 422 CONFIG_VALIDATION_FAILED. */
    public function testInvalidSlotSizeReturns422(): void
    {
        $this->seedBranch();

        $response = $this->request(
            'POST',
            '/api/admin/v1/stores/'.BranchFactory::KYIV_ID.'/configurations',
            content: json_encode([
                'slotSizeMinutes' => 45,
                'maxVehicleWeightTons' => 10,
                'receivingWindows' => [['dayOfWeek' => 1, 'intervals' => [['from' => '06:00', 'to' => '12:00']]]],
                'ramps' => [['rampId' => 'r1', 'number' => 1]],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('CONFIG_VALIDATION_FAILED', $this->json($response)['code']);
    }

    public function testConfigurationIsCreatedWithVersionOne(): void
    {
        $this->seedBranch();

        $response = $this->request(
            'POST',
            '/api/admin/v1/stores/'.BranchFactory::KYIV_ID.'/configurations',
            content: json_encode([
                'slotSizeMinutes' => 30,
                'maxVehicleWeightTons' => 12.5,
                'receivingWindows' => [['dayOfWeek' => 1, 'intervals' => [['from' => '06:00', 'to' => '12:00']]]],
                'ramps' => [['rampId' => 'r1', 'number' => 1, 'name' => 'Рампа 1']],
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame(1, $body['version']);
        self::assertTrue($body['configured']);
        self::assertSame(12.5, $body['maxVehicleWeightTons']);
    }

    /** STC-04: postачальник бачить лише active + visibleToSuppliers. */
    public function testSupplierStoresEndpointHidesNotConfiguredBranches(): void
    {
        $this->seedBranch();

        $body = $this->json($this->request('GET', '/api/supplier/v1/stores', headers: IdentityHeaders::supplier()));

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
    }

    public function testSupplierSeesActivatedAndVisibleStore(): void
    {
        $this->seedActiveVisibleBranch();

        $stores = $this->json($this->request(
            'GET',
            '/api/supplier/v1/stores?city=%D0%9A%D0%B8%D1%97%D0%B2',
            headers: IdentityHeaders::supplier(),
        ));
        $cities = $this->json($this->request('GET', '/api/supplier/v1/cities', headers: IdentityHeaders::supplier()));

        self::assertSame(1, $stores['total']);
        self::assertSame('1998', $stores['items'][0]['externalId']);
        self::assertSame([['city' => 'Київ', 'storeCount' => 1]], $cities['items']);
    }

    /** SUP-03: непорожній X-Store-Ids звужує вибірку постачальника. */
    public function testSupplierStoreIdsHeaderNarrowsResults(): void
    {
        $this->seedActiveVisibleBranch();

        $body = $this->json($this->request(
            'GET',
            '/api/supplier/v1/stores',
            headers: IdentityHeaders::supplier(storeIds: ['11111111-1111-4111-8111-111111111111']),
        ));

        self::assertSame(0, $body['total']);
    }

    public function testSyncRunEndpointReturnsReport(): void
    {
        $body = $this->json($this->request('POST', '/api/admin/v1/sync/run'));

        self::assertSame('success', $body['status']);
        self::assertSame(455, $body['fetched'], 'джерело за замовчуванням — фікстура довідника');
        self::assertSame(10, $body['ineligible']);

        $log = $this->json($this->request('GET', '/api/admin/v1/sync/log'));

        self::assertSame(1, $log['total']);
        self::assertFalse($log['running']);
    }

    /** Невалідний JSON у тілі — теж помилка у форматі problem+json. */
    public function testMalformedJsonReturnsProblemJson(): void
    {
        $this->seedBranch();

        $response = $this->request(
            'PATCH',
            '/api/admin/v1/stores/'.BranchFactory::KYIV_ID,
            content: '{не json',
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('INVALID_JSON', $this->json($response)['code']);
    }

    private function seedBranch(): Branch
    {
        $branch = BranchFactory::branch();

        $repository = $this->container->get(BranchRepository::class);
        self::assertInstanceOf(BranchRepository::class, $repository);
        $repository->save($branch);

        return $branch;
    }

    private function seedActiveVisibleBranch(): Branch
    {
        $configs = $this->container->get(StoreConfigurationRepository::class);
        self::assertInstanceOf(StoreConfigurationRepository::class, $configs);
        $configs->save(BranchFactory::completeConfiguration());

        $branch = $this->seedBranch();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $branch->changeStatus(YmsStatus::Active, BranchFactory::completeConfiguration()->readiness(), $now);
        $branch->setVisibleToSuppliers(true, $now);

        $repository = $this->container->get(BranchRepository::class);
        self::assertInstanceOf(BranchRepository::class, $repository);
        $repository->save($branch);

        return $branch;
    }

    /**
     * За замовчуванням запит іде з ідентичністю мережевої ролі: шлюз підставляє
     * службові заголовки в КОЖЕН запит, тож «запит без ідентичності» — це вже
     * окремий негативний сценарій (IdentityScopeTest).
     *
     * @param array<string, string> $headers
     */
    private function request(string $method, string $uri, ?string $content = null, array $headers = []): Response
    {
        $request = Request::create($uri, $method, server: $headers + IdentityHeaders::staff(), content: $content);

        if (null !== $content) {
            $request->headers->set('Content-Type', 'application/json');
        }

        return $this->kernel->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
