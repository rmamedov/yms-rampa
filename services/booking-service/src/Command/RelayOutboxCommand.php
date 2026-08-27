<?php

declare(strict_types=1);

namespace App\Command;

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
 *
 * Код виходу:
 *   0 — черга порожня або все доставлено;
 *   1 — сусід недоступний: НІЧОГО не втрачено, записи лишилися в черзі;
 *   2 — пакет доїхав, але частина подій непридатна (див. попередження).
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $maxBatches = max(1, (int) $input->getOption('max-batches'));

        try {
            $report = $this->relay->relay($batchSize, $maxBatches);
        } catch (UpstreamUnavailableException $error) {
            // Записи лишилися неопублікованими — наступний прогін повторить.
            $io->error(\sprintf('Доставку зупинено: %s. Події лишилися в черзі.', $error->getMessage()));

            return Command::FAILURE;
        }

        if ($report->isEmpty()) {
            $io->success('Черга outbox порожня — доставляти нічого.');

            return Command::SUCCESS;
        }

        $sink = $report->sink;

        $io->success(\sprintf(
            'Доставлено подій: %d (пакетів %d). Аналітика: застосовано %d, дублікатів %d, проігноровано %d, сиріт %d.',
            $report->delivered,
            $report->batches,
            $sink->applied,
            $sink->duplicate,
            $sink->ignored,
            $sink->orphan,
        ));

        if (!$report->queueDrained) {
            $io->note('Стелю пакетів вичерпано — решта черги поїде наступного прогону.');
        }

        if (!$sink->hasProblems()) {
            return Command::SUCCESS;
        }

        // Мовчазної втрати подій бути не повинно: у журнал systemd має
        // потрапити рівно те, що аналітика НЕ прийняла.
        if ($sink->orphan > 0) {
            $io->warning(\sprintf(
                'Подій без BookingCreated (сиріт): %d. Ці бронювання не потраплять у KPI.',
                $sink->orphan,
            ));
        }

        foreach ($sink->failed as $failure) {
            $io->warning(\sprintf(
                'Подію %s відхилено: %s',
                $failure['eventId'] ?? '(без eventId)',
                $failure['reason'],
            ));
        }

        return Command::INVALID;
    }
}
