<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Application\Outbox\AnalyticsEventSink;
use App\Application\Outbox\OutboxRelay;
use App\Application\Outbox\SinkReport;
use App\Command\RelayOutboxCommand;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Консольна обгортка релея outbox для systemd-таймера (щохвилини).
 *
 * Код виходу тут — не формальність: за ним systemd вирішує, чи писати збій
 * у журнал, а черга подій має рухатися далі навіть після невдалого прогону.
 */
#[CoversClass(RelayOutboxCommand::class)]
final class RelayOutboxCommandTest extends TestCase
{
    public function testCommandDeliversPendingEventsAndReportsCounters(): void
    {
        $scenario = new Scenario();
        $scenario->book();

        $tester = $this->tester($scenario, new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                return new SinkReport(applied: \count($events));
            }
        });

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Доставлено подій:', $tester->getDisplay());
        self::assertSame([], $scenario->outbox->pending());
    }

    public function testEmptyQueueIsReportedAndSucceeds(): void
    {
        $scenario = new Scenario();

        $tester = $this->tester($scenario, new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                return new SinkReport();
            }
        });

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Черга outbox порожня', $tester->getDisplay());
    }

    /**
     * Сусід недоступний — це збій прогону (код 1), але НЕ втрата подій:
     * черга лишається наповненою і наступна хвилина повторить доставку.
     */
    public function testUnavailableNeighbourFailsTheRunButKeepsTheQueue(): void
    {
        $scenario = new Scenario();
        $scenario->book();
        $pendingBefore = \count($scenario->outbox->pending());

        $tester = $this->tester($scenario, new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                throw UpstreamUnavailableException::analyticsService('Connection refused');
            }
        });

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Події лишилися в черзі', $tester->getDisplay());
        self::assertCount($pendingBefore, $scenario->outbox->pending());
    }

    /**
     * Проблемні події не мовчать: сироти і відхилення потрапляють у вивід,
     * а код виходу відрізняється від успішного прогону.
     */
    public function testProblemEventsAreLoudAndChangeTheExitCode(): void
    {
        $scenario = new Scenario();
        $scenario->book();

        $tester = $this->tester($scenario, new class implements AnalyticsEventSink {
            public function deliver(array $events): SinkReport
            {
                return new SinkReport(
                    orphan: 1,
                    failed: [['eventId' => 'ob-000001', 'reason' => 'Подія без поля city.']],
                );
            }
        });

        self::assertSame(Command::INVALID, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('сиріт', $display);
        self::assertStringContainsString('ob-000001', $display);
        self::assertStringContainsString('Подія без поля city.', $display);
    }

    private function tester(Scenario $scenario, AnalyticsEventSink $sink): CommandTester
    {
        return new CommandTester(new RelayOutboxCommand(
            new OutboxRelay($scenario->outbox, $sink, $scenario->clock),
        ));
    }
}
