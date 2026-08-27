<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Dto\Payload;
use App\Application\Service\StoreAdminService;
use App\Application\Service\StoreCatalogService;
use App\Domain\Branch\YmsStatus;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\FrozenClock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemoryStoreConfigurationRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Прикладний шар картки магазину: STC-01..STC-04, STL-01, STL-04, UI-02.
 */
#[CoversClass(StoreAdminService::class)]
#[CoversClass(StoreCatalogService::class)]
final class StoreAdminServiceTest extends TestCase
{
    private const string SECOND_ID = '1eda8887-bf7c-6f38-b0cb-9503162b5586';

    private InMemoryBranchRepository $branches;
    private InMemoryStoreConfigurationRepository $configs;
    private StoreCatalogService $catalog;
    private StoreAdminService $admin;
    private InMemoryEventPublisher $events;

    protected function setUp(): void
    {
        $this->branches = new InMemoryBranchRepository();
        $this->configs = new InMemoryStoreConfigurationRepository();
        $this->events = new InMemoryEventPublisher();
        $clock = new FrozenClock('2026-08-27T08:00:00+00:00');

        $this->catalog = new StoreCatalogService($this->branches, $this->configs, $clock);
        $this->admin = new StoreAdminService($this->branches, $this->catalog, $this->events, $clock);

        $this->branches->save(BranchFactory::branch());
    }

    /** STC-01: MCP-поля read-only навіть для super_admin. */
    public function testMcpFieldsCannotBeEdited(): void
    {
        try {
            $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload(['city' => 'Львів']));
            self::fail('Очікувалась ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('MCP_FIELD_READ_ONLY', $e->errorCode());
        }
    }

    public function testYmsFieldsAreUpdated(): void
    {
        $card = $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload([
            'displayName' => 'Сільпо Івасюка',
            'phone' => '+380441234567',
            'addressOverride' => 'вʼїзд з двору',
        ]));

        self::assertSame('Сільпо Івасюка', $card['displayName']);
        self::assertSame('+380441234567', $card['phone']);
        self::assertSame('вʼїзд з двору', $card['effectiveAddress']);
        self::assertNotSame([], $this->events->ofName('StoreConfigChanged'));
    }

    /** STC-03: активація неможлива без конфігурації. */
    public function testActivationWithoutConfigurationFails(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не завершено налаштування магазину');

        $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload(['ymsStatus' => 'active']));
    }

    public function testActivationSucceedsAfterConfiguration(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $card = $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload(['ymsStatus' => 'active']));

        self::assertSame('active', $card['ymsStatus']);
        self::assertTrue($card['configured']);
    }

    /** DATA-08: видимість вмикається лише після активації. */
    public function testVisibilityBeforeActivationIsRejected(): void
    {
        $this->expectException(ConflictException::class);

        $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload(['visibleToSuppliers' => true]));
    }

    public function testUnknownStatusIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Невідомий статус');

        $this->admin->updateYmsFields(BranchFactory::KYIV_ID, new Payload(['ymsStatus' => 'deleted']));
    }

    public function testUnknownStoreReturnsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->catalog->card('11111111-1111-4111-8111-111111111111');
    }

    /** STL-04: ознака «Налаштовано» рахується сервером, а не фронтендом. */
    public function testListReportsConfiguredFlagAndRampCount(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $result = $this->catalog->list([]);

        self::assertSame(1, $result['total']);
        self::assertTrue($result['items'][0]['configured']);
        self::assertSame(2, $result['items'][0]['rampCount'], 'вимкнена рампа не рахується');
        self::assertSame(10.0, $result['items'][0]['maxVehicleWeightTons']);
    }

    public function testListReportsMissingSettingsWhenNotConfigured(): void
    {
        $result = $this->catalog->list([]);

        self::assertFalse($result['items'][0]['configured']);
        self::assertContains('вікна прийому', $result['items'][0]['missingSettings']);
    }

    /** STL-02: фільтр «Налаштовано / Не налаштовано». */
    public function testConfiguredFilter(): void
    {
        $this->branches->save(BranchFactory::branch(['branchId' => self::SECOND_ID, 'externalId' => '2025']));
        $this->configs->save(BranchFactory::completeConfiguration());

        self::assertSame(1, $this->catalog->list(['configured' => 'true'])['total']);
        self::assertSame(1, $this->catalog->list(['configured' => 'false'])['total']);
        self::assertSame(2, $this->catalog->list([])['total']);
    }

    /** STL-06: порожній результат супроводжується поясненням. */
    public function testEmptyResultCarriesMessage(): void
    {
        $result = $this->catalog->list(['q' => 'неіснуюча адреса']);

        self::assertSame(0, $result['total']);
        self::assertSame('Магазинів за заданими умовами не знайдено', $result['emptyMessage']);
    }

    /** STL-03: пошук за підрядком адреси без урахування регістру. */
    public function testSearchByAddressSubstringIsCaseInsensitive(): void
    {
        self::assertSame(1, $this->catalog->list(['q' => 'ІВАСЮКА'])['total']);
        self::assertSame(1, $this->catalog->list(['q' => 'івасюка'])['total']);
    }

    /** UI-01: розмір сторінки обмежений набором 20/50/100. */
    public function testInvalidPageSizeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Розмір сторінки');

        $this->catalog->list(['perPage' => 33]);
    }

    /** UI-02: масова зміна статусу повертає зведення «успішно / з помилкою». */
    public function testBulkStatusChangeReportsPerStoreOutcome(): void
    {
        $this->branches->save(BranchFactory::branch(['branchId' => self::SECOND_ID, 'externalId' => '2025']));
        $this->configs->save(BranchFactory::completeConfiguration());

        $result = $this->admin->bulkChangeStatus([BranchFactory::KYIV_ID, self::SECOND_ID], 'active');

        self::assertSame(2, $result['requested']);
        self::assertSame([BranchFactory::KYIV_ID], $result['succeeded']);
        self::assertCount(1, $result['failed']);
        self::assertSame('STORE_NOT_CONFIGURED', $result['failed'][0]['code']);
    }

    /** Картка містить read-only блок MCP і перелік дозволених переходів. */
    public function testCardExposesMcpBlockAndAllowedTransitions(): void
    {
        $card = $this->catalog->card(BranchFactory::KYIV_ID);

        self::assertSame('1998', $card['mcpData']['externalId']);
        self::assertSame('Київ', $card['mcpData']['city']);
        self::assertTrue($card['mcpData']['open']);
        self::assertSame(['active', 'archived'], $card['allowedTransitions']);
        self::assertTrue($card['eligible']);
        self::assertSame([], $card['ineligibilityReasons']);
    }
}
