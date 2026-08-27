<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Booking\NoShowSweeper;
use App\Domain\Booking\Booking;
use App\Domain\Shared\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * NOSH-01: cron кожну хвилину переводить прострочені бронювання в `no_show`.
 *
 * Приклад crontab:
 *   * * * * * php bin/console yms:bookings:no-show
 */
#[AsCommand(
    name: 'yms:bookings:no-show',
    description: 'Перевести прострочені бронювання у статус no_show (NOSH-01)',
)]
final class NoShowSweepCommand extends Command
{
    public function __construct(
        private readonly NoShowSweeper $sweeper,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'now',
            null,
            InputOption::VALUE_REQUIRED,
            'Момент часу для прогону у форматі ISO 8601 (для відтворення інцидентів)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $nowOption = $input->getOption('now');

        $now = \is_string($nowOption) && '' !== $nowOption
            ? new DateTimeImmutable($nowOption, new DateTimeZone('UTC'))
            : $this->clock->now();

        $swept = $this->sweeper->sweep($now);

        if ([] === $swept) {
            $io->success('Прострочених бронювань не знайдено');

            return Command::SUCCESS;
        }

        $io->table(
            ['Бронювання', 'Магазин', 'Рампа', 'Кінець слоту (UTC)'],
            array_map(static fn (Booking $booking) => [
                $booking->id,
                $booking->storeId,
                $booking->rampId(),
                $booking->slotEnd->format('Y-m-d H:i'),
            ], $swept),
        );

        $io->success(\sprintf('Переведено в no_show: %d', \count($swept)));

        return Command::SUCCESS;
    }
}
