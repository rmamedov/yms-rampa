<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\Dto\Payload;
use App\Application\Service\ReservedSlotRuleService;
use App\Application\Service\SlotBlockService;
use App\Application\Service\StoreCatalogService;
use App\Application\Service\StoreConfigurationService;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\FrozenClock;
use App\Domain\Shared\ValidationException;
use App\Infrastructure\InMemory\InMemoryBranchRepository;
use App\Infrastructure\InMemory\InMemoryEventPublisher;
use App\Infrastructure\InMemory\InMemoryReservedSlotRuleRepository;
use App\Infrastructure\InMemory\InMemorySlotBlockRepository;
use App\Infrastructure\InMemory\InMemoryStoreConfigurationRepository;
use App\Tests\Support\BranchFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Прикладний шар конфігурації, резервів і блокувань: STC-42, STC-50..STC-52, DATA-09.
 */
#[CoversClass(StoreConfigurationService::class)]
#[CoversClass(ReservedSlotRuleService::class)]
#[CoversClass(SlotBlockService::class)]
final class StoreConfigurationServiceTest extends TestCase
{
    private InMemoryStoreConfigurationRepository $configs;
    private InMemoryReservedSlotRuleRepository $rules;
    private InMemorySlotBlockRepository $blocks;
    private InMemoryEventPublisher $events;
    private StoreConfigurationService $configurations;
    private ReservedSlotRuleService $reserved;
    private SlotBlockService $slotBlocks;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $branches = new InMemoryBranchRepository([BranchFactory::branch()]);
        $this->configs = new InMemoryStoreConfigurationRepository();
        $this->rules = new InMemoryReservedSlotRuleRepository();
        $this->blocks = new InMemorySlotBlockRepository();
        $this->events = new InMemoryEventPublisher();
        // Четвер, 27.08.2026, 08:00 UTC (11:00 за Києвом).
        $this->clock = new FrozenClock('2026-08-27T08:00:00+00:00');

        $catalog = new StoreCatalogService($branches, $this->configs, $this->clock);

        $this->configurations = new StoreConfigurationService($this->configs, $catalog, $this->events, $this->clock);
        $this->reserved = new ReservedSlotRuleService($this->rules, $catalog, $this->events, $this->clock);
        $this->slotBlocks = new SlotBlockService($this->blocks, $catalog, $this->events, $this->clock);
    }

    /** DATA-09: кожне збереження створює нову версію, а не оновлює наявну. */
    public function testEachSaveCreatesNewVersion(): void
    {
        $first = $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());
        $second = $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());

        self::assertSame(1, $first['version']);
        self::assertSame(2, $second['version']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertCount(2, $this->configs->findAllForStore(BranchFactory::KYIV_ID));
    }

    /**
     * Кожна наступна версія теж діє з сьогодні: правило «не раніше завтра»
     * (STC-60) знято на вимогу експлуатації — зміни мають застосовуватися
     * негайно.
     */
    public function testSubsequentVersionMayTakeEffectToday(): void
    {
        $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());

        $result = $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['effectiveFrom' => '2026-08-27T00:00:00+00:00']),
        );

        self::assertSame(2, $result['version']);
        self::assertSame('2026-08-27T00:00:00+00:00', $result['effectiveFrom']);
    }

    /** Конфігурація може діяти вже сьогодні — і перша, і будь-яка наступна. */
    public function testFirstConfigurationMayTakeEffectToday(): void
    {
        $result = $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['effectiveFrom' => '2026-08-26T21:00:00+00:00']),
        );

        self::assertSame(1, $result['version']);
        self::assertSame('2026-08-26T21:00:00+00:00', $result['effectiveFrom']);
    }

    /** Дата в минулому неприйнятна навіть для першої версії. */
    public function testFirstConfigurationCannotTakeEffectInThePast(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('заднім числом');

        $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['effectiveFrom' => '2026-08-20T00:00:00+00:00']),
        );
    }

    /**
     * Без явної дати конфігурація діє З МОМЕНТУ ЗБЕРЕЖЕННЯ, а не з наступного
     * дня: саме цього очікує адміністратор, натискаючи «Зберегти».
     */
    public function testEffectiveFromDefaultsToNow(): void
    {
        $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());
        $result = $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());

        // Годинник тесту зупинено на 2026-08-27T08:00:00Z.
        self::assertSame('2026-08-27T08:00:00+00:00', $result['effectiveFrom']);

        // І ця версія одразу є чинною, без очікування наступної доби.
        self::assertSame(2, $this->configurations->current(BranchFactory::KYIV_ID)['version']);
    }

    /** STC-20: розмір слоту поза enum відхиляється з кодом CONFIG_VALIDATION_FAILED. */
    public function testInvalidSlotSizeIsRejectedWithConfigCode(): void
    {
        try {
            $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload(['slotSizeMinutes' => 45]));
            self::fail('Очікувалась ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('CONFIG_VALIDATION_FAILED', $e->errorCode());
            self::assertSame(422, $e->httpStatus());
        }
    }

    public function testConfigurationIsReadBackAsCurrentAfterEffectiveDate(): void
    {
        $this->configurations->createVersion(BranchFactory::KYIV_ID, $this->payload());

        $this->clock->advance('+3 days');
        $current = $this->configurations->current(BranchFactory::KYIV_ID);

        self::assertSame(1, $current['version']);
        self::assertTrue($current['configured']);
        self::assertSame(30, $current['slotSizeMinutes']);
    }

    /**
     * Явно майбутню дату через API все ще можна задати — тоді до неї діє
     * попередня версія. Інтерфейс так не робить: там чинність негайна.
     */
    public function testFutureVersionDoesNotOverrideCurrentBeforeEffectiveDate(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration(maxWeight: 10.0));

        $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['maxVehicleWeightTons' => 20.0, 'effectiveFrom' => '2026-09-10T00:00:00+00:00']),
        );

        self::assertSame(10.0, $this->configurations->current(BranchFactory::KYIV_ID)['maxVehicleWeightTons']);

        $this->clock->advance('+20 days');

        self::assertSame(20.0, $this->configurations->current(BranchFactory::KYIV_ID)['maxVehicleWeightTons']);
    }

    /**
     * Екран налаштувань має бачити щойно збережену версію, навіть поки вона
     * не набрала чинності: current() її не показує — це робота latest().
     *
     * Без цього розмежування збережена зміна зникає з екрана одразу після
     * збереження, і виглядає це як «не зберігається». Саме так проявився
     * дефект із розкладом прийому на неділю.
     */
    public function testLatestSeesFutureVersionWhileCurrentStillShowsEffectiveOne(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration(maxWeight: 10.0));

        $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['maxVehicleWeightTons' => 20.0, 'effectiveFrom' => '2026-09-10T00:00:00+00:00']),
        );

        self::assertSame(10.0, $this->configurations->current(BranchFactory::KYIV_ID)['maxVehicleWeightTons']);
        self::assertSame(20.0, $this->configurations->latest(BranchFactory::KYIV_ID)['maxVehicleWeightTons']);
    }

    /** Вікно на неділю (ISO 7) зберігається і читається без втрат. */
    public function testSundayReceivingWindowSurvivesRoundTrip(): void
    {
        $windows = [];

        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $windows[] = ['dayOfWeek' => $day, 'intervals' => [['from' => '09:00', 'to' => '12:00']]];
        }

        $this->configurations->createVersion(
            BranchFactory::KYIV_ID,
            $this->payload(['receivingWindows' => $windows]),
        );

        $latest = $this->configurations->latest(BranchFactory::KYIV_ID);
        $days = array_map(static fn (array $w): int => $w['dayOfWeek'], $latest['receivingWindows']);

        self::assertSame([1, 2, 3, 4, 5, 6, 7], $days);
    }

    /** STC-42: резерв поза вікном прийому відхиляється. */
    public function testReservedRuleOutsideReceivingWindowIsRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не потрапляє в жодне вікно прийому');

        $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '20:00',
            'dayOfWeek' => 1,
        ]));
    }

    /** STC-42: резерв на вимкнену рампу відхиляється. */
    public function testReservedRuleOnDisabledRampIsRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('вимкнену рампу');

        $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r3',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]));
    }

    public function testReservedRuleOnUnknownRampIsRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не знайдено в конфігурації');

        $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r42',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]));
    }

    public function testValidReservedRuleIsStored(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $rule = $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]));

        self::assertSame('supplier-1', $rule['supplierId']);
        self::assertSame(1, $rule['dayOfWeek']);
        self::assertCount(1, $this->reserved->list(BranchFactory::KYIV_ID));
    }

    /** STC-42: перетин двох правил резерву на один слот заборонений. */
    public function testOverlappingReservedRulesAreRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $payload = new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]);

        $this->reserved->create(BranchFactory::KYIV_ID, $payload);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('Перетин двох правил резерву');

        $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-2',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]));
    }

    public function testReservedRuleWithoutConfigurationIsRejected(): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('ще не задано конфігурацію');

        $this->reserved->create(BranchFactory::KYIV_ID, new Payload([
            'supplierId' => 'supplier-1',
            'rampId' => 'r1',
            'slotStartTime' => '08:00',
            'dayOfWeek' => 1,
        ]));
    }

    /** STC-50: разове блокування з обовʼязковою причиною. */
    public function testSlotBlockRequiresReason(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Причина блокування');

        $this->slotBlocks->create(BranchFactory::KYIV_ID, new Payload([
            'rampIds' => ['r1'],
            'blockFrom' => '2026-08-28T06:00:00+00:00',
            'blockTo' => '2026-08-28T12:00:00+00:00',
        ]));
    }

    public function testSlotBlockInThePastIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('минулий період');

        $this->slotBlocks->create(BranchFactory::KYIV_ID, new Payload([
            'blockFrom' => '2026-08-01T06:00:00+00:00',
            'blockTo' => '2026-08-01T12:00:00+00:00',
            'reason' => 'Ремонт',
        ]));
    }

    /** STC-52: зняття блокування породжує подію SlotReleased. */
    public function testReleasingBlockEmitsSlotReleased(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $block = $this->slotBlocks->create(BranchFactory::KYIV_ID, new Payload([
            'rampIds' => ['r1', 'r2'],
            'blockFrom' => '2026-08-28T06:00:00+00:00',
            'blockTo' => '2026-08-28T12:00:00+00:00',
            'reason' => 'Планове обслуговування рамп',
        ]));

        $this->events->clear();
        $released = $this->slotBlocks->release(BranchFactory::KYIV_ID, $block['id']);

        self::assertNotNull($released['releasedAt']);

        $slotReleased = $this->events->ofName('SlotReleased');

        self::assertCount(1, $slotReleased);
        self::assertSame(['r1', 'r2'], $slotReleased[0]->payload()['rampIds']);
    }

    public function testReleasingAlreadyReleasedBlockIsRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $block = $this->slotBlocks->create(BranchFactory::KYIV_ID, new Payload([
            'blockFrom' => '2026-08-28T06:00:00+00:00',
            'blockTo' => '2026-08-28T12:00:00+00:00',
            'reason' => 'Ремонт',
        ]));

        $this->slotBlocks->release(BranchFactory::KYIV_ID, $block['id']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('вже знято');

        $this->slotBlocks->release(BranchFactory::KYIV_ID, $block['id']);
    }

    public function testSlotBlockOnUnknownRampIsRejected(): void
    {
        $this->configs->save(BranchFactory::completeConfiguration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не знайдено в конфігурації');

        $this->slotBlocks->create(BranchFactory::KYIV_ID, new Payload([
            'rampIds' => ['r42'],
            'blockFrom' => '2026-08-28T06:00:00+00:00',
            'blockTo' => '2026-08-28T12:00:00+00:00',
            'reason' => 'Ремонт',
        ]));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function payload(array $overrides = []): Payload
    {
        return new Payload(array_replace([
            'slotSizeMinutes' => 30,
            'maxVehicleWeightTons' => 10.0,
            'receivingWindows' => [
                ['dayOfWeek' => 1, 'intervals' => [['from' => '06:00', 'to' => '12:00']]],
            ],
            'ramps' => [
                ['rampId' => 'r1', 'number' => 1, 'name' => 'Рампа 1', 'active' => true],
            ],
        ], $overrides));
    }
}
