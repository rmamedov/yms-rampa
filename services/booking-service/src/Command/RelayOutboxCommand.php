<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Outbox\EventOutcome;
use App\Application\Outbox\OutboxRelay;
use App\Domain\Exception\UpstreamUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DATA-16 / KPI-05: публікація подій outbox споживачам.
 *
 * Запускається за розкладом (systemd-таймер, щохвилини — див.
 * infra/deploy-api.sh). Прогони не перетинаються: таймер налаштований на
 * одиничний екземпляр служби, а сама команда швидко завершується, коли черга
 * порожня.
 *
 * Приклад:
 *   php bin/console yms:outbox:relay
 *   php bin/console yms:outbox:relay --batch-size=50 --max-batches=5
 *   php bin/console yms:outbox:relay --requeue-failed   # після виправлення подій
 *
 * Код виходу:
 *   0 — черга порожня або все доставлено;
 *   1 — сусід недоступний: НІЧОГО не втрачено, записи лишилися в черзі;
 *   2 — частина подій пішла в карантин (див. попередження). Це теж не втрата:
 *       такі записи лишаються в outbox із причиною і повертаються в чергу
 *       ключем --requeue-failed.
 */
#[AsCommand(
    name: 'yms:outbox:relay',
    description: 'Доставити події outbox у read-моделі аналітики (DATA-16, KPI-05)',
)]
final class RelayOutboxCommand extends Command
{
    public function __construct(private readonly OutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'batch-size',
            null,
            InputOption::VALUE_REQUIRED,
            'Скільки подій іде в одному пакеті',
            (string) OutboxRelay::DEFAULT_BATCH_SIZE,
        );

        $this->addOption(
            'max-batches',
            null,
            InputOption::VALUE_REQUIRED,
            'Стеля пакетів за один прогін (решта поїде наступного разу)',
            (string) OutboxRelay::DEFAULT_MAX_BATCHES,
        );

        $this->addOption(
            'requeue-failed',
            null,
            InputOption::VALUE_NONE,
            'Спершу повернути в чергу всі записи з карантину (після виправлення формату подій)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $maxBatches = max(1, (int) $input->getOption('max-batches'));

        if (true === $input->getOption('requeue-failed')) {
            $requeued = $this->relay->requeueQuarantined();
            $io->note(\sprintf('Повернуто з карантину в чергу: %d.', $requeued));
        }

        try {
            $report = $this->relay->relay($batchSize, $maxBatches);
        } catch (UpstreamUnavailableException $error) {
            // Записи лишилися неопублікованими — наступний прогін повторить.
            $io->error(\sprintf('Доставку зупинено: %s. Події лишилися в черзі.', $error->getMessage()));

            return Command::FAILURE;
        }

        if ($report->isEmpty()) {
            $io->success(\sprintf(
                'Черга outbox порожня — доставляти нічого. У карантині: %d.',
                $report->quarantineTotal,
            ));

            return Command::SUCCESS;
        }

        $sink = $report->sink;

        $io->success(\sprintf(
            'Доставлено подій: %d (пакетів %d). Аналітика: застосовано %d, дублікатів %d, проігноровано %d.',
            $report->delivered,
            $report->batches,
            $sink->count(EventOutcome::Applied),
            $sink->count(EventOutcome::Duplicate),
            $sink->count(EventOutcome::Ignored),
        ));

        if (!$report->queueDrained) {
            $io->note('Стелю пакетів вичерпано — решта черги поїде наступного прогону.');
        }

        if (0 === $report->quarantined) {
            return Command::SUCCESS;
        }

        // Мовчазної втрати подій бути не повинно: у журнал systemd має
        // потрапити рівно те, що аналітика НЕ прийняла, і що з ним сталося.
        $io->warning(\sprintf(
            'У карантин відправлено подій: %d (сиріт %d, відхилено %d). Усього в карантині: %d. '
            .'Події НЕ втрачені — після виправлення формату поверніть їх у чергу: '
            .'php bin/console yms:outbox:relay --requeue-failed',
            $report->quarantined,
            $sink->count(EventOutcome::Orphan),
            $sink->count(EventOutcome::Rejected),
            $report->quarantineTotal,
        ));

        foreach ($sink->undelivered() as $row) {
            $io->writeln(\sprintf(
                '  <comment>%s</comment>: %s',
                $row['outcome']->label(),
                $row['reason'] ?? 'причину не вказано',
            ));
        }

        return Command::INVALID;
    }
}
