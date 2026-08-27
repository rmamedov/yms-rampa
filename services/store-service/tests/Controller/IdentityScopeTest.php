<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Access\Actor;
use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchRepository;
use App\Infrastructure\Http\ActorResolver;
use App\Kernel;
use App\Tests\Support\BranchFactory;
use App\Tests\Support\IdentityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Наскрізна перевірка скоупу на HTTP-рівні (RBAC-AC-08).
 *
 * Ключове правило: для store_manager і store_operator ПОРОЖНІЙ X-Store-Ids —
 * це нуль доступу, а не «усі магазини». Скоуп «вся мережа» дає роль (RBAC-16).
 */
#[CoversClass(ActorResolver::class)]
#[CoversClass(Actor::class)]
final class IdentityScopeTest extends TestCase
{
    private const string STORE_A = BranchFactory::KYIV_ID;
    private const string STORE_B = '22222222-2222-4222-8222-222222222222';
    private const string STORE_C = '33333333-3333-4333-8333-333333333333';

    private Kernel $kernel;
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $this->container = $container;

        $this->seedStores();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    // --- Магазинна роль із ПОРОЖНІМ переліком: нуль доступу (RBAC-13) ---

    /** Колекція фільтрується мовчки — жодного магазину (RBAC-18). */
    #[DataProvider('storeRoleProvider')]
    public function testStoreRoleWithEmptyScopeSeesNoStores(string $role): void
    {
        $body = $this->json($this->get('/api/admin/v1/stores', IdentityHeaders::staff($role)));

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);

        $cities = $this->json($this->get('/api/admin/v1/stores/cities', IdentityHeaders::staff($role)));

        self::assertSame([], $cities['items']);
    }

    /** Читання картки магазину — 404, наче магазину не існує. */
    #[DataProvider('storeRoleProvider')]
    public function testStoreRoleWithEmptyScopeCannotReadAnyStore(string $role): void
    {
        $response = $this->get('/api/admin/v1/stores/'.self::STORE_A, IdentityHeaders::staff($role));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('STORE_NOT_FOUND', $this->json($response)['code']);
    }

    /** Дія над магазином — 403 RBAC_SCOPE_VIOLATION. */
    #[DataProvider('storeRoleProvider')]
    public function testStoreRoleWithEmptyScopeCannotActOnAnyStore(string $role): void
    {
        $response = $this->patch(
            '/api/admin/v1/stores/'.self::STORE_A,
            ['displayName' => 'Спроба'],
            IdentityHeaders::staff($role),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('RBAC_SCOPE_VIOLATION', $this->json($response)['code']);
    }

    /** Вкладені ресурси картки магазину підкоряються тому самому правилу. */
    public function testStoreRoleWithEmptyScopeCannotReachNestedResources(): void
    {
        $headers = IdentityHeaders::staff('store_manager');

        foreach (['configurations', 'slot-blocks', 'reserved-slot-rules'] as $resource) {
            $read = $this->get('/api/admin/v1/stores/'.self::STORE_A.'/'.$resource, $headers);

            self::assertSame(Response::HTTP_NOT_FOUND, $read->getStatusCode(), $resource);
            self::assertSame('STORE_NOT_FOUND', $this->json($read)['code'], $resource);

            $write = $this->post('/api/admin/v1/stores/'.self::STORE_A.'/'.$resource, [], $headers);

            self::assertSame(Response::HTTP_FORBIDDEN, $write->getStatusCode(), $resource);
            self::assertSame('RBAC_SCOPE_VIOLATION', $this->json($write)['code'], $resource);
        }
    }

    // --- Магазинна роль зі списком [A, B] ---

    public function testStoreRoleSeesOnlyItsOwnStores(): void
    {
        $headers = IdentityHeaders::staff('store_manager', [self::STORE_A, self::STORE_B]);

        $body = $this->json($this->get('/api/admin/v1/stores', $headers));

        self::assertSame(2, $body['total']);
        self::assertSame(
            [self::STORE_A, self::STORE_B],
            array_column($body['items'], 'branchId'),
        );
    }

    public function testStoreRoleReadsStoresFromItsListAndNotOthers(): void
    {
        $headers = IdentityHeaders::staff('store_operator', [self::STORE_A, self::STORE_B]);

        self::assertSame(Response::HTTP_OK, $this->get('/api/admin/v1/stores/'.self::STORE_A, $headers)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->get('/api/admin/v1/stores/'.self::STORE_B, $headers)->getStatusCode());

        $foreign = $this->get('/api/admin/v1/stores/'.self::STORE_C, $headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $foreign->getStatusCode());
        self::assertSame('STORE_NOT_FOUND', $this->json($foreign)['code']);
    }

    public function testStoreRoleActsOnItsOwnStoreButNotOnForeignOne(): void
    {
        $headers = IdentityHeaders::staff('store_manager', [self::STORE_A, self::STORE_B]);

        $own = $this->patch('/api/admin/v1/stores/'.self::STORE_B, ['displayName' => 'Свій магазин'], $headers);

        self::assertSame(Response::HTTP_OK, $own->getStatusCode());
        self::assertSame('Свій магазин', $this->json($own)['displayName']);

        $foreign = $this->patch('/api/admin/v1/stores/'.self::STORE_C, ['displayName' => 'Чужий'], $headers);

        self::assertSame(Response::HTTP_FORBIDDEN, $foreign->getStatusCode());
        self::assertSame('RBAC_SCOPE_VIOLATION', $this->json($foreign)['code']);
    }

    /** Масова дія відхиляється цілком, якщо в переліку є магазин поза скоупом. */
    public function testBulkActionRejectsForeignStoreWithoutChangingAnything(): void
    {
        $headers = IdentityHeaders::staff('store_manager', [self::STORE_A]);

        $response = $this->post(
            '/api/admin/v1/stores/bulk/status',
            ['branchIds' => [self::STORE_A, self::STORE_C], 'ymsStatus' => 'not_configured'],
            $headers,
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('RBAC_SCOPE_VIOLATION', $this->json($response)['code']);
    }

    // --- Мережеві ролі: доступ до будь-якого магазину (RBAC-16) ---

    #[DataProvider('networkRoleProvider')]
    public function testNetworkRoleReachesEveryStore(string $role): void
    {
        $headers = IdentityHeaders::staff($role);

        $list = $this->json($this->get('/api/admin/v1/stores', $headers));

        self::assertSame(3, $list['total']);

        foreach ([self::STORE_A, self::STORE_B, self::STORE_C] as $storeId) {
            self::assertSame(
                Response::HTTP_OK,
                $this->get('/api/admin/v1/stores/'.$storeId, $headers)->getStatusCode(),
                $storeId,
            );
        }
    }

    /** Мережева роль не звужується навіть тоді, коли шлюз передав перелік магазинів. */
    public function testNetworkRoleIgnoresStoreIdsHeader(): void
    {
        $headers = IdentityHeaders::staff('network_manager', [self::STORE_A]);

        self::assertSame(3, $this->json($this->get('/api/admin/v1/stores', $headers))['total']);
        self::assertSame(
            Response::HTTP_OK,
            $this->get('/api/admin/v1/stores/'.self::STORE_C, $headers)->getStatusCode(),
        );
    }

    // --- Партнерський контур ---

    /** RBAC-14: роль постачальника з порожнім X-Supplier-Id — відмова. */
    #[DataProvider('supplierRoleProvider')]
    public function testSupplierWithoutSupplierIdIsDenied(string $role): void
    {
        $response = $this->get(
            '/api/supplier/v1/stores',
            IdentityHeaders::raw('partner-1', $role, supplierId: '', contour: 'partner'),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', $this->json($response)['code']);
    }

    public function testSupplierWithSupplierIdIsAllowed(): void
    {
        $response = $this->get('/api/supplier/v1/stores', IdentityHeaders::supplier());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // --- Загальні правила ідентичності ---

    public function testRequestWithoutIdentityHeadersIsDenied(): void
    {
        $response = $this->kernel->handle(Request::create('/api/admin/v1/stores'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', $this->json($response)['code']);
    }

    public function testUnknownRoleIsDenied(): void
    {
        $response = $this->get('/api/admin/v1/stores', IdentityHeaders::raw('staff-1', 'root', contour: 'staff'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', $this->json($response)['code']);
    }

    /** RBAC-19: контур маршруту перевіряється повторно самим сервісом (RBAC-20). */
    public function testPartnerIdentityCannotReachAdminRoutes(): void
    {
        $response = $this->get('/api/admin/v1/stores', IdentityHeaders::supplier());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testStaffIdentityCannotReachSupplierRoutes(): void
    {
        $response = $this->get('/api/supplier/v1/stores', IdentityHeaders::staff());

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function storeRoleProvider(): iterable
    {
        yield 'store_manager' => ['store_manager'];
        yield 'store_operator' => ['store_operator'];
    }

    /** @return iterable<string, array{string}> */
    public static function networkRoleProvider(): iterable
    {
        yield 'super_admin' => ['super_admin'];
        yield 'network_manager' => ['network_manager'];
        yield 'analyst' => ['analyst'];
    }

    /** @return iterable<string, array{string}> */
    public static function supplierRoleProvider(): iterable
    {
        yield 'supplier_admin' => ['supplier_admin'];
        yield 'supplier_operator' => ['supplier_operator'];
    }

    private function seedStores(): void
    {
        $repository = $this->container->get(BranchRepository::class);
        self::assertInstanceOf(BranchRepository::class, $repository);

        $repository->saveAll([
            BranchFactory::branch(),
            self::branch(self::STORE_B, '2000', 'Львів'),
            self::branch(self::STORE_C, '3000', 'Одеса'),
        ]);
    }

    private static function branch(string $branchId, string $externalId, string $city): Branch
    {
        return BranchFactory::branch([
            'branchId' => $branchId,
            'externalId' => $externalId,
            'city' => $city,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $uri, array $headers): Response
    {
        return $this->kernel->handle(Request::create($uri, 'GET', server: $headers));
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function patch(string $uri, array $payload, array $headers): Response
    {
        return $this->send('PATCH', $uri, $payload, $headers);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function post(string $uri, array $payload, array $headers): Response
    {
        return $this->send('POST', $uri, $payload, $headers);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private function send(string $method, string $uri, array $payload, array $headers): Response
    {
        $request = Request::create(
            $uri,
            $method,
            server: $headers,
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

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
