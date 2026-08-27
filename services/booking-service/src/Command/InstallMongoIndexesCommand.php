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
use Throwable;

/**
 * Створення індексів БД `bookings`, найважливіший з яких — DATA-12:
 * частковий унікальний індекс на ключ слота, що робить подвійне
 * бронювання рампи фізично неможливим (BOOK-07/BOOK-08).
 *
 * Команда не входить у гарячий шлях і виконується під час деплою.
 */
#[AsCommand(
    name: 'yms:mongo:install-indexes',
    description: 'Створити індекси MongoDB для booking-service (DATA-12 та інші)',
)]
final class InstallMongoIndexesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('uri', null, InputOption::VALUE_REQUIRED, 'URI MongoDB', 'mongodb://127.0.0.1:27017')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Назва бази даних', 'bookings')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати перелік індексів');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!\extension_loaded('mongodb')) {
            $io->error('PHP-розширення mongodb не встановлено — індекси створити неможливо');

            return Command::FAILURE;
        }

        $uri = (string) $input->getOption('uri');
        $database = (string) $input->getOption('database');

        try {
            $connection = new MongoConnection(new \MongoDB\Driver\Manager($uri), $database);
        } catch (Throwable $error) {
            $io->error(\sprintf('Не вдалося підключитися до MongoDB: %s', $error->getMessage()));

            return Command::FAILURE;
        }

        $installer = new MongoIndexInstaller($connection);

        if (true === $input->getOption('dry-run')) {
            foreach ($installer->definitions() as $collection => $indexes) {
                foreach ($indexes as $index) {
                    $io->writeln(\sprintf('%s.%s: %s', $collection, $index['name'], json_encode($index['key'], \JSON_THROW_ON_ERROR)));
                }
            }

            return Command::SUCCESS;
        }

        try {
            $created = $installer->install();
        } catch (Throwable $error) {
            $io->error(\sprintf('Помилка створення індексів: %s', $error->getMessage()));

            return Command::FAILURE;
        }

        $io->listing($created);
        $io->success(\sprintf('Створено або підтверджено індексів: %d', \count($created)));

        return Command::SUCCESS;
    }
}
