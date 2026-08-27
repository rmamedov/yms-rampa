<?php

declare(strict_types=1);

namespace App\Tests\Domain\Booking;

use App\Domain\Access\Actor;
use App\Domain\Access\Role;
use App\Domain\Booking\BookingStatus;
use App\Domain\Booking\StatusChange;
use App\Infrastructure\Http\BookingPresenter;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Журнал дій бронювання (DATA-14): колонка «Хто».
 *
 * Дефект, який тут закривається: у журналі виконавець показувався голим
 * UUID облікового запису, бо `by` — це рівно він. Запис тепер несе ще й роль
 * та контур виконавця, тож журнал читається без звернень до сусідніх сервісів.
 */
#[CoversClass(StatusChange::class)]
final class StatusHistoryActorTest extends TestCase
{
    /** Перехід ST-01 підписаний роллю того, хто його виконав. */
    public function testTransitionRecordsRoleAndContourOfActor(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:58'));
        $scenario->lifecycle->markArrived(
            $scenario->storeStaff(Role::StoreOperator),
            $booking->id,
            $scenario->now(),
        );

        $entry = $scenario->reload($booking)->statusHistory()[1];

        self::assertSame(BookingStatus::Arrived, $entry->to);
        self::assertSame('su-1', $entry->by);
        self::assertSame(Role::StoreOperator, $entry->byRole);
        self::assertSame('staff', $entry->contour()?->value);
        self::assertSame('Приймальник магазину', $entry->actorLabel());
    }

    /** Створення бронювання підписане контуром партнера. */
    public function testCreationRecordIsSignedByPartnerContour(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $entry = $booking->statusHistory()[0];

        self::assertNull($entry->from);
        self::assertSame(Role::SupplierAdmin, $entry->byRole);
        self::assertSame('partner', $entry->contour()?->value);
        self::assertSame('Адміністратор постачальника', $entry->actorLabel());
    }

    /** NOSH-01: у автоматичного no_show людини-виконавця немає взагалі. */
    public function testSystemSweepIsLabelledAsScheduledJob(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $scenario->clock->set(Scenario::kyiv('2026-08-28 11:05'));
        $scenario->sweeper->sweep($scenario->now());

        $entry = $scenario->reload($booking)->statusHistory()[1];

        self::assertSame(BookingStatus::NoShow, $entry->to);
        self::assertTrue($entry->bySystem);
        self::assertSame('system', $entry->contour()?->value);
        self::assertSame('Планове завдання системи', $entry->actorLabel());
    }

    /** Водій, який відмітився «На місці», теж підписаний людиночитано. */
    public function testDriverTransitionIsLabelled(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00', driverId: 'du-7');

        $scenario->clock->set(Scenario::kyiv('2026-08-28 09:58'));
        $scenario->lifecycle->markArrived($scenario->driver('du-7'), $booking->id, $scenario->now());

        $entry = $scenario->reload($booking)->statusHistory()[1];

        self::assertSame(Role::Driver, $entry->byRole);
        self::assertSame('Водій', $entry->actorLabel());
        self::assertSame('partner', $entry->contour()?->value);
    }

    /** Відповідь API несе всі три поля журналу поруч із `by`. */
    public function testPresenterExposesActorFields(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $entry = BookingPresenter::toArray($booking)['statusHistory'][0];

        self::assertSame('pu-sp-1', $entry['by']);
        self::assertSame('supplier_admin', $entry['byRole']);
        self::assertSame('partner', $entry['byContour']);
        self::assertSame('Адміністратор постачальника', $entry['byLabel']);
    }

    /**
     * DATA-02: записи, зроблені до появи полів, читаються як «роль невідома».
     * Підставляти сюди ідентифікатор не можна — саме це й було дефектом.
     */
    public function testLegacyRecordWithoutRoleIsHonestlyUnknown(): void
    {
        $legacy = new StatusChange(
            from: BookingStatus::Booked,
            to: BookingStatus::Arrived,
            at: new \DateTimeImmutable('2026-08-01T09:00:00Z'),
            by: '5f3a9c1e-0000-4000-8000-000000000001',
        );

        self::assertNull($legacy->byRole);
        self::assertNull($legacy->contour());
        self::assertNull($legacy->actorLabel());
        self::assertNull($legacy->toArray()['byLabel']);
    }

    /** Кожна канонічна роль має підпис — жодна не лишає журнал порожнім. */
    #[DataProvider('roles')]
    public function testEveryRoleHasHumanReadableLabel(Role $role): void
    {
        $entry = StatusChange::madeBy(
            null,
            BookingStatus::Booked,
            new \DateTimeImmutable('2026-08-01T09:00:00Z'),
            new Actor('u-1', $role, supplierId: $role->isSupplier() ? 'sp-1' : null),
        );

        self::assertNotSame('', (string) $entry->actorLabel());
        self::assertNotSame($entry->by, $entry->actorLabel());
        self::assertContains($entry->contour()?->value, ['staff', 'partner']);
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function roles(): iterable
    {
        foreach (Role::cases() as $role) {
            yield $role->value => [$role];
        }
    }
}
