<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Service\BranchSyncService;
use App\Domain\Sync\SyncStatus;
use App\Domain\Sync\SyncTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Планова синхронізація довідника філій з MCP (INT-04: cron щоночі о 03:00 Europe/Kyiv).
 */
#[AsCommand(
    name: 'yms:branches:sync',
    description: 'Синхронізує довідник філій із джерелом MCP (нічний cron о 03:00 Europe/Kyiv)',
)]
final class SyncBranchesCommand extends Command
{
    public function __construct(
        private readonly BranchSyncService $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('initiator', null, InputOption::VALUE_REQUIRED, 'Ініціатор запуску', 'cron');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->sync->run(SyncTrigger::Cron, (string) $input->getOption('initiator'));

        $io->table(
            ['Показник', 'Значення'],
            [
                ['Статус', $report->status->label()],
                ['Отримано', (string) $report->fetched],
                ['Створено', (string) $report->created],
                ['Оновлено', (string) $report->updated],
                ['Зниклих', (string) $report->missing],
                ['Архівовано', (string) $report->archived],
                ['Відхилено', (string) $report->skipped],
            ],
        );

        if (SyncStatus::Failed === $report->status) {
            // INT-13: невдалий синк не змінює даних; система працює на останніх успішних.
            $io->error('Синхронізацію не завершено. Дані не змінено.');

            foreach ($report->errors as $error) {
                $io->text('• '.$error);
            }

            return Command::FAILURE;
        }

        $io->success('Синхронізацію завершено.');

        return Command::SUCCESS;
    }
}
