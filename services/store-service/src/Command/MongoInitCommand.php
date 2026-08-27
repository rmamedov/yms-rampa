<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Mongo\MongoConnection;
use App\Infrastructure\Mongo\MongoIndexInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення індексів БД `stores` (10.2). Команда безпечна на машині без MongoDB:
 * за відсутності розширення або сервера вона повідомляє про це і завершується без падіння.
 */
#[AsCommand(
    name: 'yms:mongo:init',
    description: 'Створює індекси колекцій store-service у MongoDB',
)]
final class MongoInitCommand extends Command
{
    public function __construct(
        private readonly MongoConnection $connection,
        private readonly MongoIndexInitializer $initializer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!MongoConnection::isAvailable()) {
            $io->warning('Розширення PHP ext-mongodb не встановлено — індекси не створено.');

            return Command::INVALID;
        }

        if (!$this->connection->ping()) {
            $io->warning(\sprintf('Сервер MongoDB недоступний за адресою %s — індекси не створено.', $this->connection->dsn()));

            return Command::INVALID;
        }

        foreach ($this->initializer->createAll() as $line) {
            $io->text('• '.$line);
        }

        $io->success(\sprintf('Індекси БД «%s» створено.', $this->connection->database()));

        return Command::SUCCESS;
    }
}
