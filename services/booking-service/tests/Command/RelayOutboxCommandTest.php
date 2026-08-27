<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Application\Outbox\AnalyticsEventSink;
use App\Application\Outbox\EventOutcome;
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

        $tester = $this->tester($scenario, $this->sink(EventOutcome::Applied));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Доставлено подій:', $tester->getDisplay());
        self::assertSame([], $scenario->outbox->pending());
        self::assertSame(0, $scenario->outbox->countQuarantined());
    }

    public function testEmptyQueueIsReportedAndSucceeds(): void
    {
        $scenario = new Scenario();

        $tester = $this->tester($scenario, $this->sink(EventOutcome::Applied));

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
     * Проблемні події не мовчать: у вивід потрапляє і кількість, і причина,
     * і підказка, як їх перепровести. Код виходу відрізняється від успішного.
     */
    public function testQuarantinedEventsAreLoudAndChangeTheExitCode(): void
    {
        $scenario = new Scenario();
        $scenario->book();

        $tester = $this->tester($scenario, $this->sink(EventOutcome::Rejected, 'Подія без поля city.'));

        self::assertSame(Command::INVALID, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('У карантин відправлено подій', $display);
        self::assertStringContainsString('Подія без поля city.', $display);
        self::assertStringContainsString('--requeue-failed', $display);
        self::assertStringContainsString('НЕ втрачені', $display);
    }

    /**
     * Головна гарантія: жодна відхилена подія не позначається доставленою.
     * Саме через це на стенді безслідно зникли 536 подій.
     */
    public function testRejectedEventsStayInOutboxAndAreNotPublished(): void
    {
        $scenario = new Scenario();
        $scenario->book();
        $total = \count($scenario->outbox->pending());

        $tester = $this->tester($scenario, $this->sink(EventOutcome::Rejected, 'Немає rampId.'));
        $tester->execute([]);

        self::assertSame($total, $scenario->outbox->countQuarantined());

        foreach ($scenario->outbox->all() as $record) {
            self::assertNull($record->publishedAt);
            self::assertTrue($record->isQuarantined());
            self::assertStringContainsString('Немає rampId.', (string) $record->failureReason);
        }
    }

    /** Після виправлення формату карантин повертається в чергу одним ключем. */
    public function testRequeueFailedReturnsQuarantineToTheQueue(): void
    {
        $scenario = new Scenario();
        $scenario->book();
        $total = \count($scenario->outbox->pending());

        $this->tester($scenario, $this->sink(EventOutcome::Rejected, 'Немає rampId.'))->execute([]);
        self::assertSame($total, $scenario->outbox->countQuarantined());

        $tester = $this->tester($scenario, $this->sink(EventOutcome::Applied));
        self::assertSame(Command::SUCCESS, $tester->execute(['--requeue-failed' => true]));

        self::assertStringContainsString('Повернуто з карантину в чергу', $tester->getDisplay());
        self::assertSame(0, $scenario->outbox->countQuarantined());
        self::assertSame([], $scenario->outbox->pending());
    }

    private function tester(Scenario $scenario, AnalyticsEventSink $sink): CommandTester
    {
        return new CommandTester(new RelayOutboxCommand(
            new OutboxRelay($scenario->outbox, $sink, $scenario->clock),
        ));
    }

    /** Приймач з однаковим присудом на всі події пакета. */
    private function sink(EventOutcome $outcome, ?string $reason = null): AnalyticsEventSink
    {
        return new class($outcome, $reason) implements AnalyticsEventSink {
            public function __construct(
                private EventOutcome $outcome,
                private ?string $reason,
            ) {
            }

            public function deliver(array $events): SinkReport
            {
                return SinkReport::fromRows(array_map(
                    fn (int $index): array => [
                        'index' => $index,
                        'outcome' => $this->outcome,
                        'reason' => $this->reason,
                    ],
                    array_keys($events),
                ));
            }
        };
    }
}
