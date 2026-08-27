<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Mongo\MongoConnectionFactory;
use App\Infrastructure\Mongo\MongoNotificationRepository;
use App\Infrastructure\Mongo\MongoScheduledReminderRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створює індекси MongoDB для черги сповіщень і нагадувань.
 *
 * Якщо розширення ext-mongodb або сам сервер недоступні, команда
 * завершується попередженням, а не помилкою: сервіс має лишатися
 * запускабельним на машині розробника без MongoDB.
 */
#[AsCommand(
    name: 'app:notifications:init-storage',
    description: 'Створює індекси MongoDB для колекцій сповіщень і нагадувань',
)]
final class InitStorageCommand extends Command
{
    public function __construct(
        private readonly MongoConnectionFactory $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!MongoConnectionFactory::isAvailable()) {
            $io->warning('MongoDB недоступна: немає розширення ext-mongodb або бібліотеки mongodb/mongodb. Індекси не створено.');

            return Command::SUCCESS;
        }

        try {
            (new MongoNotificationRepository($this->connection))->ensureIndexes();
            (new MongoScheduledReminderRepository($this->connection))->ensureIndexes();
        } catch (\Throwable $e) {
            $io->error('Не вдалося створити індекси: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Індекси створено у базі «%s».', $this->connection->databaseName()));

        return Command::SUCCESS;
    }
}
