<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Branch\IneligibilityReason;
use App\Domain\Sync\BranchSynchronizer;
use App\Domain\Sync\SyncStatus;
use App\Domain\Sync\SyncTrigger;
use App\Infrastructure\Fixture\FixtureBranchSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Імпорт довідника філій із фікстури MCP (fixtures/silpo-branches.json).
 *
 * Правила фільтрації (fixtures/README.md) застосовуються до кожного запису:
 * непридатні філії все одно імпортуються зі статусом not_configured і збереженою
 * причиною непридатності, але ніколи не показуються постачальникам (INT-07, STC-04).
 */
#[AsCommand(
    name: 'yms:branches:import',
    description: 'Імпортує довідник філій із фікстури MCP із застосуванням правил фільтрації',
)]
final class ImportBranchesCommand extends Command
{
    public function __construct(
        private readonly BranchSynchronizer $synchronizer,
        private readonly string $defaultFixturePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Шлях до JSON-фікстури довідника філій', $this->defaultFixturePath)
            ->addOption('initiator', null, InputOption::VALUE_REQUIRED, 'Ініціатор імпорту для журналу синхронізацій', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getOption('file');

        $io->title('Імпорт довідника філій із фікстури MCP');
        $io->text(\sprintf('Файл: %s', $path));

        $report = $this->synchronizer->synchronize(
            new FixtureBranchSource($path),
            SyncTrigger::Import,
            (string) $input->getOption('initiator'),
        );

        if (SyncStatus::Failed === $report->status) {
            $io->error('Імпорт не виконано — дані не змінено.');

            foreach ($report->errors as $error) {
                $io->text('• '.$error);
            }

            return Command::FAILURE;
        }

        $io->section('Підсумок');
        $io->table(
            ['Показник', 'Значення'],
            [
                ['Отримано записів', (string) $report->fetched],
                ['Створено нових філій', (string) $report->created],
                ['Оновлено філій', (string) $report->updated],
                ['Зниклих у вибірці', (string) $report->missing],
                ['Архівовано', (string) $report->archived],
                ['Відхилено (порушення контракту)', (string) $report->skipped],
                ['Непридатні до активації', (string) $report->ineligible],
                ['Придатні до активації', (string) $report->eligible()],
            ],
        );

        if ([] !== $report->ineligibleByReason) {
            $io->section('Причини непридатності');
            $rows = [];

            foreach ($report->ineligibleByReason as $code => $count) {
                $reason = IneligibilityReason::tryFrom($code);
                $rows[] = [$code, $reason?->message() ?? '—', (string) $count];
            }

            $io->table(['Код', 'Опис', 'Кількість'], $rows);
        }

        if ([] !== $report->errors) {
            $io->section('Помилки записів');

            foreach (\array_slice($report->errors, 0, 20) as $error) {
                $io->text('• '.$error);
            }
        }

        $io->success(\sprintf(
            'Імпорт завершено зі статусом «%s» за %.3f с.',
            $report->status->label(),
            $report->durationSeconds(),
        ));

        return Command::SUCCESS;
    }
}
