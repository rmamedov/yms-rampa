<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\NoShowSweepCommand;
use App\Domain\Booking\BookingStatus;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Консольна обгортка NOSH-01 для cron (кожну хвилину).
 */
#[CoversClass(NoShowSweepCommand::class)]
final class NoShowSweepCommandTest extends TestCase
{
    public function testCommandMarksOverdueBookingsAsNoShow(): void
    {
        $scenario = new Scenario();
        $booking = $scenario->book('2026-08-28 10:00');

        $tester = new CommandTester(new NoShowSweepCommand($scenario->sweeper, $scenario->clock));
        $exitCode = $tester->execute(['--now' => '2026-08-28T08:05:00Z']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Переведено в no_show: 1', $tester->getDisplay());
        self::assertSame(BookingStatus::NoShow, $scenario->reload($booking)->status());
    }

    public function testCommandReportsWhenNothingToSweep(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');

        $tester = new CommandTester(new NoShowSweepCommand($scenario->sweeper, $scenario->clock));
        $exitCode = $tester->execute(['--now' => '2026-08-28T07:45:00Z']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Прострочених бронювань не знайдено', $tester->getDisplay());
    }

    public function testCommandUsesClockWhenNowIsNotProvided(): void
    {
        $scenario = new Scenario();
        $scenario->book('2026-08-28 10:00');
        $scenario->clock->set(Scenario::kyiv('2026-08-28 11:05'));

        $tester = new CommandTester(new NoShowSweepCommand($scenario->sweeper, $scenario->clock));
        $tester->execute([]);

        self::assertStringContainsString('Переведено в no_show: 1', $tester->getDisplay());
    }
}
