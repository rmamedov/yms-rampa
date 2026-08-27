<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Exception\MalformedEventException;
use App\Domain\Projection\ProjectionOutcome;
use App\Infrastructure\Messaging\DomainEventConsumer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Завантаження потоку доменних подій із NDJSON-файлу в read-моделі.
 *
 * Використовується для відтворення історії (backfill) і для локальної розробки
 * без піднятого RabbitMQ; у проді той самий DomainEventConsumer викликається
 * з транспорту Messenger.
 */
#[AsCommand(
    name: 'analytics:events:ingest',
    description: 'Завантажити доменні події з NDJSON-файлу в read-моделі аналітики',
)]
final class IngestEventsCommand extends Command
{
    public function __construct(private readonly DomainEventConsumer $consumer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Шлях до файлу NDJSON (одна подія на рядок)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');

        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Файл «%s» не знайдено або недоступний для читання.', $file));

            return Command::FAILURE;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $io->error('Не вдалося відкрити файл подій.');

            return Command::FAILURE;
        }

        $counters = array_fill_keys(array_map(
            static fn (ProjectionOutcome $o): string => $o->value,
            ProjectionOutcome::cases(),
        ), 0);
        $errors = 0;
        $lineNumber = 0;

        while (($line = fgets($handle)) !== false) {
            ++$lineNumber;
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $result = $this->consumer->consumeJson($line);
                ++$counters[$result->outcome->value];
            } catch (MalformedEventException $exception) {
                ++$errors;
                $io->warning(sprintf('Рядок %d: %s', $lineNumber, $exception->getMessage()));
            }
        }

        fclose($handle);

        $io->success(sprintf(
            'Оброблено подій: застосовано %d, дублікатів %d, проігноровано %d, сиріт %d, помилок %d.',
            $counters[ProjectionOutcome::Applied->value],
            $counters[ProjectionOutcome::Duplicate->value],
            $counters[ProjectionOutcome::Ignored->value],
            $counters[ProjectionOutcome::Orphan->value],
            $errors,
        ));

        return $errors > 0 ? Command::INVALID : Command::SUCCESS;
    }
}
