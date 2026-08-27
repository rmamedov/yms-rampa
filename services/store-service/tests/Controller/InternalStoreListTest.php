<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Dto\BranchPresenter;
use App\Application\Service\StoreCatalogService;
use App\Controller\Internal\InternalStoreController;
use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Shared\Timezone;
use App\Kernel;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Службовий контракт store-service → booking-service: GET /internal/v1/stores.
 *
 * З цього переліку модуль магазину будує перемикач філії, тому головна вимога
 * тут — у переліку немає магазину, для якого /settings відповість 404: інакше
 * перемикач пропонує пункт, що ламає кожен наступний екран.
 *
 * Запити НАВМИСНО без заголовків ідентичності: службові маршрути не проходять
 * через auth_request.
 */
#[CoversClass(InternalStoreController::class)]
#[CoversClass(StoreCatalogService::class)]
#[CoversClass(BranchPresenter::class)]
final class InternalStoreListTest extends TestCase
{
    private const string ENDPOINT = '/internal/v1/stores';
    private const string PODIL_ID = '1ed43e73-051b-6842-a111-a5ad042eb497';
    private const string LVIV_ID = '1ed43e73-051b-6842-a111-a5ad042eb498';

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

    /** Форма рядка повторює «шапку» /settings: storeId + ymsStatus + снапшот. */
    public function testItemRepeatsSettingsHeaderShape(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');

        $body = $this->json($this->get());

        self::assertSame(['items', 'total', 'page', 'perPage', 'pages'], array_keys($body));
        self::assertCount(1, $body['items']);
        self::assertSame(['storeId', 'ymsStatus', 'snapshot'], array_keys($body['items'][0]));
        self::assertSame(BranchFactory::KYIV_ID, $body['items'][0]['storeId']);
        self::assertSame('active', $body['items'][0]['ymsStatus']);
        self::assertSame(
            ['externalId', 'displayName', 'city', 'address'],
            array_keys($body['items'][0]['snapshot']),
        );
        self::assertSame('1998', $body['items'][0]['snapshot']['externalId']);
    }

    /** Магазин на паузі у перелік не потрапляє: для нього немає сітки слотів. */
    public function testPausedStoreIsNotListed(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->seedOperationalStore(self::PODIL_ID, '2001', status: YmsStatus::Paused);

        $body = $this->json($this->get());

        self::assertSame([BranchFactory::KYIV_ID], array_column($body['items'], 'storeId'));
    }

    /** Ненастроєна філія теж не потрапляє — /settings відповів би 404. */
    public function testStoreWithoutConfigurationIsNotListed(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->branches()->save(BranchFactory::branch(['branchId' => self::LVIV_ID, 'externalId' => '3050']));

        $body = $this->json($this->get());

        self::assertSame([BranchFactory::KYIV_ID], array_column($body['items'], 'storeId'));
    }

    /** RBAC-17: скоуп звужує вибірку предикатом запиту, а не пост-фільтром. */
    public function testScopeNarrowsSelection(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->seedOperationalStore(self::PODIL_ID, '2001');

        $body = $this->json($this->get('?storeIds='.self::PODIL_ID));

        self::assertSame([self::PODIL_ID], array_column($body['items'], 'storeId'));
        self::assertSame(1, $body['total']);
    }

    /** RBAC-13: порожній параметр скоупу = нуль магазинів, а не вся мережа. */
    public function testEmptyScopeParameterYieldsEmptySelection(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->seedOperationalStore(self::PODIL_ID, '2001');

        $body = $this->json($this->get('?storeIds='));

        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
    }

    /** Без параметра скоупу віддається вся мережа — це запит мережевої ролі. */
    public function testMissingScopeParameterReturnsWholeNetwork(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->seedOperationalStore(self::PODIL_ID, '2001');

        self::assertSame(2, $this->json($this->get())['total']);
    }

    /** Пагінація серверна: клієнт має чим гортати до кінця. */
    public function testPaginationReportsPageCount(): void
    {
        $this->seedOperationalStore(BranchFactory::KYIV_ID, '1998');
        $this->seedOperationalStore(self::PODIL_ID, '2001');

        $body = $this->json($this->get('?page=1&perPage=20'));

        self::assertSame(20, $body['perPage']);
        self::assertSame(1, $body['pages']);
        self::assertSame(2, $body['total']);
    }

    /** Службовий маршрут працює без заголовків ідентичності. */
    public function testRouteDoesNotRequireIdentityHeaders(): void
    {
        self::assertSame(Response::HTTP_OK, $this->get()->getStatusCode());
    }

    // --- інфраструктура тесту ----------------------------------------------

    private function seedOperationalStore(
        string $storeId,
        string $externalId,
        YmsStatus $status = YmsStatus::Active,
    ): Branch {
        $configuration = BranchFactory::completeConfiguration(storeId: $storeId);
        $this->configurations()->save($configuration);

        $branch = BranchFactory::branch(['branchId' => $storeId, 'externalId' => $externalId]);
        $now = new \DateTimeImmutable('now', Timezone::storage());

        $branch->changeStatus(YmsStatus::Active, $configuration->readiness(), $now);

        if (YmsStatus::Active !== $status) {
            $branch->changeStatus($status, $configuration->readiness(), $now);
        }

        $this->branches()->save($branch);

        return $branch;
    }

    private function branches(): BranchRepository
    {
        $repository = $this->container->get(BranchRepository::class);
        self::assertInstanceOf(BranchRepository::class, $repository);

        return $repository;
    }

    private function configurations(): StoreConfigurationRepository
    {
        $repository = $this->container->get(StoreConfigurationRepository::class);
        self::assertInstanceOf(StoreConfigurationRepository::class, $repository);

        return $repository;
    }

    private function get(string $query = ''): Response
    {
        return $this->kernel->handle(Request::create(self::ENDPOINT.$query, 'GET'));
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
