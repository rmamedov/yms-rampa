<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoIndexInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення індексів БД `partners` (DATA-28: індекси — лише через міграції/команди).
 *
 * Команда не падає на машині без MongoDB: якщо розширення чи сервер
 * недоступні, вона повідомляє про це і завершується без помилки,
 * а з ключем `--dry-run` просто друкує заплановані індекси.
 */
#[AsCommand(
    name: 'app:partner:ensure-indexes',
    description: 'Створює індекси колекцій suppliers, partner_users, vehicles у MongoDB',
)]
final class EnsureIndexesCommand extends Command
{
    public function __construct(
        private readonly MongoConnection $connection,
        private readonly MongoIndexInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Лише показати перелік індексів, нічого не створювати',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            foreach ($this->installer->definitions() as $collection => $indexes) {
                foreach ($indexes as $index) {
                    $io->writeln(\sprintf(
                        '%s: %s (%s)',
                        $collection,
                        $index['name'],
                        ($index['unique'] ?? false) ? 'unique' : 'звичайний',
                    ));
                }
            }

            return Command::SUCCESS;
        }

        if (!MongoConnection::isDriverAvailable()) {
            $io->warning('Розширення PHP «mongodb» не встановлено — індекси не створено.');

            return Command::SUCCESS;
        }

        if (!$this->connection->isServerReachable()) {
            $io->warning('Сервер MongoDB недоступний — індекси не створено.');

            return Command::SUCCESS;
        }

        $created = $this->installer->install();

        $io->success(\sprintf('Створено або підтверджено %d індексів.', \count($created)));
        $io->listing($created);

        return Command::SUCCESS;
    }
}
