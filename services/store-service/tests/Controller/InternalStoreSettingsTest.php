<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\Dto\StoreSettingsPresenter;
use App\Application\Service\StoreSettingsService;
use App\Domain\Branch\Branch;
use App\Domain\Branch\BranchRepository;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\CalendarException;
use App\Domain\Configuration\CalendarExceptionType;
use App\Domain\Configuration\Ramp;
use App\Domain\Configuration\ReceivingWindow;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\ReservedSlotRuleRepository;
use App\Domain\Configuration\SlotBlock;
use App\Domain\Configuration\SlotBlockRepository;
use App\Domain\Configuration\SlotSize;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Configuration\StoreConfigurationRepository;
use App\Domain\Configuration\TimeInterval;
use App\Domain\Shared\Timezone;
use App\Domain\Shared\Uuid;
use App\Kernel;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Службовий контракт store-service → booking-service:
 * GET /internal/v1/stores/{storeId}/settings.
 *
 * Запити НАВМИСНО йдуть без заголовків ідентичності: службові маршрути не проходять
 * через auth_request і не отримують X-User-Id / X-User-Role. Якщо на них колись
 * зʼявиться перевірка ідентичності, ці тести впадуть на 403 — і це правильно.
 */
#[CoversClass(StoreSettingsService::class)]
#[CoversClass(StoreSettingsPresenter::class)]
#[CoversClass(\App\Controller\Internal\InternalStoreController::class)]
final class InternalStoreSettingsTest extends TestCase
{
    private const string ENDPOINT = '/internal/v1/stores/%s/settings';
    private const string UNKNOWN_STORE_ID = '11111111-1111-4111-8111-111111111111';

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

    /**
     * Повний набір полів, потрібних booking-service для сітки і валідації:
     * склад верхнього рівня зафіксовано, бо це контракт HttpStoreConfigProvider.
     */
    public function testActiveStoreReturnsFullSettingsContract(): void
    {
        $this->seedActiveStore();

        $response = $this->get(BranchFactory::KYIV_ID);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame([
            'storeId',
            'ymsStatus',
            'visibleToSuppliers',
            'snapshot',
            'configVersion',
            'effectiveFrom',
            'receivingWindows',
            'slotSizeMinutes',
            'ramps',
            'maxVehicleWeightTons',
            'leadTimeMinutes',
            'bookingHorizonDays',
            'noShowGraceMinutes',
            'holdMaxMinutes',
            'calendarExceptions',
            'reservedSlotRules',
            'slotBlocks',
        ], array_keys($body));

        self::assertSame(BranchFactory::KYIV_ID, $body['storeId']);
        self::assertSame('active', $body['ymsStatus']);
        self::assertTrue($body['visibleToSuppliers']);
        self::assertSame(30, $body['slotSizeMinutes']);
        // Ціла маса виїжджає в JSON як 10, а не 10.0 (PHP не зберігає нульову дробову
        // частину) — споживач приводить значення до float, тож контракт не порушено.
        self::assertSame(10.0, (float) $body['maxVehicleWeightTons']);
        self::assertSame(90, $body['leadTimeMinutes']);
        self::assertSame(7, $body['bookingHorizonDays']);
        self::assertSame(45, $body['noShowGraceMinutes']);
        self::assertSame(20, $body['holdMaxMinutes']);
        self::assertSame(1, $body['configVersion']);

        // DATA-13: снапшот філії для документа бронювання.
        self::assertSame([
            'externalId' => '1998',
            'displayName' => 'просп. Володимира Івасюка, 46',
            'city' => 'Київ',
            'address' => 'просп. Володимира Івасюка, 46',
        ], $body['snapshot']);

        // Вікна прийому на дні тижня з інтервалами {from,to}.
        self::assertSame(
            ['dayOfWeek' => 2, 'intervals' => [['from' => '06:00', 'to' => '12:00'], ['from' => '14:00', 'to' => '18:00']]],
            $body['receivingWindows'][1],
        );

        // Рампи віддаються всі, вимкнена — з active=false.
        self::assertCount(3, $body['ramps']);
        self::assertSame(['rampId' => 'r1', 'number' => 1, 'name' => 'Рампа 1', 'active' => true], $body['ramps'][0]);
        self::assertFalse($body['ramps'][2]['active']);

        // Виняток календаря несе БУЛЕВЕ closed — саме його розбирає booking-service.
        self::assertSame([
            [
                'date' => '2026-12-31',
                'closed' => true,
                'reason' => 'Інвентаризація',
                'intervals' => [],
            ],
        ], $body['calendarExceptions']);

        self::assertSame([], $body['reservedSlotRules']);
        self::assertSame([], $body['slotBlocks']);
    }

    /** Магазин активний, але жодної версії конфігурації не збережено. */
    public function testStoreWithoutConfigurationReturns404(): void
    {
        $this->seedBranch(active: true);

        $response = $this->get(BranchFactory::KYIV_ID);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = $this->json($response);

        self::assertSame('STORE_NOT_CONFIGURED', $body['code']);
        self::assertSame(404, $body['status']);
        self::assertStringContainsString('немає чинної версії конфігурації', $body['detail']);
    }

    /** STL-04: конфігурація без вікон прийому і без активних рамп — магазин не «налаштований». */
    public function testStoreWithIncompleteConfigurationReturns404(): void
    {
        $this->seedConfiguration(BranchFactory::incompleteConfiguration());
        $this->seedBranch(active: true);

        $body = $this->json($this->get(BranchFactory::KYIV_ID));

        self::assertSame('STORE_NOT_CONFIGURED', $body['code']);
        self::assertStringContainsString('вікна прийому', $body['detail']);
        self::assertStringContainsString('активні рампи', $body['detail']);
    }

    /** STC-05: магазин на паузі — сітку по ньому будувати не можна. */
    public function testPausedStoreReturns404(): void
    {
        $this->seedActiveStore(status: YmsStatus::Paused);

        $response = $this->get(BranchFactory::KYIV_ID);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());

        $body = $this->json($response);

        self::assertSame('STORE_NOT_CONFIGURED', $body['code']);
        self::assertStringContainsString('На паузі', $body['detail']);
    }

    /** Нова філія з MCP (not_configured) теж не існує для бронювання. */
    public function testNotConfiguredStatusReturns404(): void
    {
        $this->seedConfiguration();
        $this->seedBranch(active: false);

        self::assertSame('STORE_NOT_CONFIGURED', $this->json($this->get(BranchFactory::KYIV_ID))['code']);
    }

    public function testUnknownStoreReturns404StoreNotFound(): void
    {
        $response = $this->get(self::UNKNOWN_STORE_ID);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));

        $body = $this->json($response);

        self::assertSame('STORE_NOT_FOUND', $body['code']);
        self::assertSame('about:blank', $body['type']);
        self::assertNotSame('', $body['requestId']);
    }

    /**
     * Резерви (STC-40..43) і блокування (STC-50..52) входять у відповідь:
     * booking-service накладає їх на обчислену сітку.
     */
    public function testReservesAndBlocksAreExposed(): void
    {
        $this->seedActiveStore();

        $now = new \DateTimeImmutable('now', Timezone::storage());

        $this->seedRule(new ReservedSlotRule(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            supplierId: 'supplier-1',
            rampId: 'r1',
            slotStartTime: '08:00',
            dayOfWeek: 2,
            date: null,
            validFrom: $now->modify('-10 days'),
            validTo: null,
        ));

        $this->seedBlock(new SlotBlock(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            rampIds: ['r2'],
            blockFrom: $now->modify('+1 day'),
            blockTo: $now->modify('+1 day')->modify('+4 hours'),
            reason: 'Ремонт рампи',
        ));

        $body = $this->json($this->get(BranchFactory::KYIV_ID));

        self::assertCount(1, $body['reservedSlotRules']);
        self::assertSame([
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 2,
            'date' => null,
            // Локальні дати Y-m-d: booking-service порівнює їх з датою слота як рядки.
            'validFrom' => Timezone::localDate($now->modify('-10 days')),
            'validTo' => null,
            'active' => true,
        ], $body['reservedSlotRules'][0]);

        self::assertCount(1, $body['slotBlocks']);
        self::assertSame(['r2'], $body['slotBlocks'][0]['rampIds']);
        self::assertFalse($body['slotBlocks'][0]['coversAllRamps']);
        self::assertSame('Ремонт рампи', $body['slotBlocks'][0]['reason']);
        self::assertSame(
            $now->modify('+1 day')->format(\DATE_ATOM),
            $body['slotBlocks'][0]['blockFrom'],
        );
    }

    /**
     * У відповідь потрапляють лише ЧИННІ накладання: неактивне правило, правило з
     * простроченим validTo, разовий резерв за горизонтом і зняте блокування сітку
     * не змінюють.
     */
    public function testStaleReservesAndReleasedBlocksAreExcluded(): void
    {
        $this->seedActiveStore();

        $now = new \DateTimeImmutable('now', Timezone::storage());

        $this->seedRule($this->rule(['active' => false]));
        $this->seedRule($this->rule([
            'validFrom' => $now->modify('-30 days'),
            'validTo' => $now->modify('-1 day'),
        ]));
        $this->seedRule($this->rule([
            'dayOfWeek' => null,
            // Горизонт цього магазину — 7 днів (див. seedActiveStore).
            'date' => Timezone::localDate($now->modify('+60 days')),
        ]));

        $block = new SlotBlock(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            rampIds: [],
            blockFrom: $now->modify('+1 day'),
            blockTo: $now->modify('+2 days'),
            reason: 'Інвентаризація',
        );
        $this->seedBlock($block->release($now));

        $body = $this->json($this->get(BranchFactory::KYIV_ID));

        self::assertSame([], $body['reservedSlotRules']);
        self::assertSame([], $body['slotBlocks']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function rule(array $overrides = []): ReservedSlotRule
    {
        $defaults = [
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 2,
            'date' => null,
            'validFrom' => new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            'validTo' => null,
            'active' => true,
        ];

        /** @var array{supplierId: string, rampId: string, slotStartTime: string, dayOfWeek: int|null, date: string|null, validFrom: \DateTimeImmutable, validTo: \DateTimeImmutable|null, active: bool} $values */
        $values = array_replace($defaults, $overrides);

        return new ReservedSlotRule(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            supplierId: $values['supplierId'],
            rampId: $values['rampId'],
            slotStartTime: $values['slotStartTime'],
            dayOfWeek: $values['dayOfWeek'],
            date: $values['date'],
            validFrom: $values['validFrom'],
            validTo: $values['validTo'],
            active: $values['active'],
        );
    }

    /**
     * Магазин, готовий до бронювання: збережена повна конфігурація + філія
     * у потрібному YMS-статусі.
     */
    private function seedActiveStore(YmsStatus $status = YmsStatus::Active): Branch
    {
        $configuration = self::configuration();
        $this->seedConfiguration($configuration);

        $branch = BranchFactory::branch();
        $now = new \DateTimeImmutable('now', Timezone::storage());

        $branch->changeStatus(YmsStatus::Active, $configuration->readiness(), $now);
        $branch->setVisibleToSuppliers(true, $now);

        if (YmsStatus::Active !== $status) {
            $branch->changeStatus($status, $configuration->readiness(), $now);
        }

        $this->branches()->save($branch);

        return $branch;
    }

    /**
     * Філія без збереженої конфігурації. Активація вимагає повної готовності
     * (STC-03), тому readiness передається окремо від сховища конфігурацій —
     * так відтворюється стан «магазин активний, а конфігурації вже/ще немає».
     */
    private function seedBranch(bool $active): Branch
    {
        $branch = BranchFactory::branch();

        if ($active) {
            $branch->changeStatus(
                YmsStatus::Active,
                BranchFactory::completeConfiguration()->readiness(),
                new \DateTimeImmutable('now', Timezone::storage()),
            );
        }

        $this->branches()->save($branch);

        return $branch;
    }

    private function seedConfiguration(?StoreConfiguration $configuration = null): void
    {
        $repository = $this->container->get(StoreConfigurationRepository::class);
        self::assertInstanceOf(StoreConfigurationRepository::class, $repository);
        $repository->save($configuration ?? self::configuration());
    }

    private function seedRule(ReservedSlotRule $rule): void
    {
        $repository = $this->container->get(ReservedSlotRuleRepository::class);
        self::assertInstanceOf(ReservedSlotRuleRepository::class, $repository);
        $repository->save($rule);
    }

    private function seedBlock(SlotBlock $block): void
    {
        $repository = $this->container->get(SlotBlockRepository::class);
        self::assertInstanceOf(SlotBlockRepository::class, $repository);
        $repository->save($block);
    }

    private function branches(): BranchRepository
    {
        $repository = $this->container->get(BranchRepository::class);
        self::assertInstanceOf(BranchRepository::class, $repository);

        return $repository;
    }

    /**
     * Конфігурація з НЕдефолтними параметрами движка: так тест ловить випадок,
     * коли presenter віддав мережевий дефолт замість значення магазину.
     */
    private static function configuration(): StoreConfiguration
    {
        return new StoreConfiguration(
            id: Uuid::v4(),
            storeId: BranchFactory::KYIV_ID,
            version: 1,
            effectiveFrom: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            receivingWindows: [
                new ReceivingWindow(1, [new TimeInterval('06:00', '12:00')]),
                new ReceivingWindow(2, [new TimeInterval('06:00', '12:00'), new TimeInterval('14:00', '18:00')]),
            ],
            slotSize: SlotSize::Half,
            ramps: [
                new Ramp('r1', 1, 'Рампа 1'),
                new Ramp('r2', 2, null),
                new Ramp('r3', 3, 'Рампа 3 (у ремонті)', false),
            ],
            maxVehicleWeightTons: 10.0,
            leadTimeMinutes: 90,
            bookingHorizonDays: 7,
            noShowGraceMinutes: 45,
            holdMaxMinutes: 20,
            calendarExceptions: [
                new CalendarException('2026-12-31', CalendarExceptionType::Closed, 'Інвентаризація'),
            ],
            createdBy: 'staff-1',
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private function get(string $storeId): Response
    {
        return $this->kernel->handle(Request::create(\sprintf(self::ENDPOINT, $storeId), 'GET'));
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
