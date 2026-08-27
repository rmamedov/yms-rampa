<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Analytics\AnalyticsQuery;
use App\Domain\Analytics\PeriodBucket;
use App\Domain\Clock\Clock;
use App\Domain\Fact\BookingFactRepository;
use App\Domain\Slot\SlotFactRepository;
use App\Domain\Stats\DailyStoreStatsBuilder;
use App\Domain\Stats\DailyStoreStatsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перерахунок агрегату DailyStoreStats за період.
 *
 * ANL-14: після перерахунку мітка recalculatedAt оновлюється; технічний SLA
 * оновлення read-моделей — не більше 60 секунд від доменної події, тому
 * команда розрахована на запуск за розкладом (наприклад, щохвилини)
 * або вручну для backfill.
 */
#[AsCommand(
    name: 'analytics:stats:rebuild',
    description: 'Перерахувати добові агрегати аналітики (магазин × доба) за період',
)]
final class RebuildDailyStatsCommand extends Command
{
    public function __construct(
        private readonly BookingFactRepository $bookingFacts,
        private readonly SlotFactRepository $slotFacts,
        private readonly DailyStoreStatsRepository $statsRepository,
        private readonly DailyStoreStatsBuilder $builder,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Початкова дата періоду (Y-m-d, зона Europe/Kyiv)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Кінцева дата періоду включно (Y-m-d, зона Europe/Kyiv)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Альтернатива: перерахувати останні N діб', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = PeriodBucket::storeTimeZone();
        $today = $this->clock->now()->setTimezone($tz)->setTime(0, 0);

        $fromOption = $input->getOption('from');
        $toOption = $input->getOption('to');

        try {
            $from = is_string($fromOption) && $fromOption !== ''
                ? (new \DateTimeImmutable($fromOption, $tz))->setTime(0, 0)
                : $today->modify('-' . max(1, (int) $input->getOption('days')) . ' days');

            $to = is_string($toOption) && $toOption !== ''
                ? (new \DateTimeImmutable($toOption, $tz))->setTime(0, 0)->modify('+1 day')
                : $today->modify('+1 day');
        } catch (\Exception) {
            $io->error('Некоректна дата періоду: очікується формат Y-m-d.');

            return Command::INVALID;
        }

        if ($from >= $to) {
            $io->error('Початок періоду не може бути пізнішим за кінець.');

            return Command::INVALID;
        }

        $query = new AnalyticsQuery(
            from: $from->setTimezone(new \DateTimeZone('UTC')),
            to: $to->setTimezone(new \DateTimeZone('UTC')),
        );

        $stats = $this->builder->build(
            $this->bookingFacts->findByQuery($query),
            $this->slotFacts->findByQuery($query),
            $this->clock->now(),
        );

        $this->statsRepository->saveMany($stats);

        $io->success(sprintf(
            'Перераховано агрегатів «магазин × доба»: %d за період %s — %s.',
            count($stats),
            $from->format('Y-m-d'),
            $to->modify('-1 day')->format('Y-m-d'),
        ));

        return Command::SUCCESS;
    }
}
