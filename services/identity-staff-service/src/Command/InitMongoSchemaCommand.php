<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Mongo\MongoConnection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення індексів БД `identity_staff` (10.5).
 *
 * Команда безпечна на машині БЕЗ ext-mongodb: у такому разі вона повідомляє
 * про відсутність розширення і завершується без помилки автозавантаження.
 */
#[AsCommand(
    name: 'yms:mongo:init',
    description: 'Створює індекси колекцій staff_users, refresh_tokens, login_attempts, role_audit (10.5)',
)]
final class InitMongoSchemaCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('uri', null, InputOption::VALUE_REQUIRED, 'URI MongoDB', null)
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Назва бази даних', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!MongoConnection::isDriverAvailable()) {
            $io->warning(
                'Розширення ext-mongodb не встановлено. Сервіс працює на InMemory-реалізаціях; '
                .'індекси буде створено після встановлення розширення та підняття MongoDB 7.x.',
            );

            return Command::SUCCESS;
        }

        $uri = (string) ($input->getOption('uri') ?? $_ENV['MONGODB_URI'] ?? 'mongodb://127.0.0.1:27017');
        $database = (string) ($input->getOption('db') ?? $_ENV['MONGODB_DB'] ?? 'identity_staff');

        try {
            $connection = new MongoConnection($uri, $database);
            $connection->ensureIndexes();
        } catch (\Throwable $exception) {
            $io->error(\sprintf('Не вдалося створити індекси: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Індекси БД "%s" створено.', $database));

        return Command::SUCCESS;
    }
}
