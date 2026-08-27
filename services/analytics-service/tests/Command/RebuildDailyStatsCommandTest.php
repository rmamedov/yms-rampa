<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\IngestEventsCommand;
use App\Command\RebuildDailyStatsCommand;
use App\Domain\Booking\BookingStatus;
use App\Domain\Projection\EventProjector;
use App\Domain\Slot\SlotState;
use App\Domain\Stats\DailyStoreStatsBuilder;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Infrastructure\InMemory\InMemoryDailyStoreStatsRepository;
use App\Infrastructure\InMemory\InMemorySlotFactRepository;
use App\Infrastructure\Messaging\DomainEventConsumer;
use App\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Консольні команди перерахунку агрегатів і завантаження потоку подій.
 */
#[CoversClass(RebuildDailyStatsCommand::class)]
#[CoversClass(IngestEventsCommand::class)]
final class RebuildDailyStatsCommandTest extends TestCase
{
    private InMemoryDailyStoreStatsRepository $stats;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $bookings = new InMemoryBookingFactRepository([
            Fixtures::booking(
                bookingId: 'b1',
                slotStart: '2026-03-16 08:00:00',
                slotEnd: '2026-03-16 08:30:00',
                status: BookingStatus::Completed,
                arrivedAt: '2026-03-16 07:55:00',
                unloadingStartedAt: '2026-03-16 08:05:00',
                completedAt: '2026-03-16 08:25:00',
            ),
            Fixtures::booking(
                bookingId: 'b2',
                slotStart: '2026-03-16 09:00:00',
                slotEnd: '2026-03-16 09:30:00',
                status: BookingStatus::NoShow,
            ),
        ]);
        $slots = new InMemorySlotFactRepository([
            Fixtures::slot('s1', SlotState::Booked, '2026-03-16 08:00:00', '2026-03-16 08:30:00'),
            Fixtures::slot('s2', SlotState::Available, '2026-03-16 08:30:00', '2026-03-16 09:00:00'),
        ]);

        $this->stats = new InMemoryDailyStoreStatsRepository();
        $this->tester = new CommandTester(new RebuildDailyStatsCommand(
            $bookings,
            $slots,
            $this->stats,
            new DailyStoreStatsBuilder(),
            new FrozenClock('2026-03-17 03:00:00'),
        ));
    }

    #[Test]
    public function rebuildsAggregatesForExplicitPeriod(): void
    {
        $exitCode = $this->tester->execute(['--from' => '2026-03-16', '--to' => '2026-03-16']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Перераховано агрегатів', $this->tester->getDisplay());

        $row = $this->stats->find('store-1', '2026-03-16');
        self::assertNotNull($row);
        self::assertSame(2, $row->bookingsTotal);
        self::assertSame(50.0, $row->utilizationPercent);
        self::assertSame(50.0, $row->noShowPercent);
        self::assertSame('2026-03-17 03:00:00', $row->recalculatedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function rejectsReversedPeriod(): void
    {
        $exitCode = $this->tester->execute(['--from' => '2026-03-20', '--to' => '2026-03-10']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Початок періоду не може бути пізнішим', $this->tester->getDisplay());
    }

    #[Test]
    public function ingestCommandLoadsNdjsonStreamIdempotently(): void
    {
        $repository = new InMemoryBookingFactRepository();
        $command = new IngestEventsCommand(new DomainEventConsumer(new EventProjector($repository)));

        $file = sys_get_temp_dir() . '/yms-analytics-events-' . uniqid() . '.ndjson';
        $event = json_encode([
            'eventId' => 'evt-1',
            'name' => 'BookingCreated',
            'occurredAt' => '2026-03-16T08:00:00+00:00',
            'payload' => Fixtures::bookingCreatedPayload(),
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($file, $event . "\n" . $event . "\n");

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['file' => $file]);
        unlink($file);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('застосовано 1', $tester->getDisplay());
        self::assertStringContainsString('дублікатів 1', $tester->getDisplay());
        self::assertSame(1, $repository->countAll());
    }

    #[Test]
    public function ingestCommandFailsOnMissingFile(): void
    {
        $tester = new CommandTester(new IngestEventsCommand(
            new DomainEventConsumer(new EventProjector(new InMemoryBookingFactRepository())),
        ));

        self::assertSame(Command::FAILURE, $tester->execute(['file' => '/неіснуючий/файл.ndjson']));
    }
}
