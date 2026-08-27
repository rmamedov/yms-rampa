<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Mongo\MongoManagerFactory;
use App\Infrastructure\Mongo\MongoSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення індексів БД `identity_partner` (10.6).
 *
 * Команда не виконується автоматично і коректно завершується з поясненням,
 * якщо ext-mongodb або сервер недоступні — це не має ламати dev-середовище.
 */
#[AsCommand(
    name: 'app:mongo:init-indexes',
    description: 'Створює індекси колекцій partner_accounts, refresh_tokens, login_attempts.',
)]
final class InitMongoIndexesCommand extends Command
{
    public function __construct(
        private readonly string $mongoUri = 'mongodb://127.0.0.1:27017',
        private readonly string $database = 'identity_partner',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('uri', null, InputOption::VALUE_REQUIRED, 'URI MongoDB', $this->mongoUri);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uri = (string) $input->getOption('uri');

        if (!MongoSupport::isDriverAvailable()) {
            $io->warning('Розширення ext-mongodb відсутнє — індекси не створено. Сервіс може працювати на InMemory-репозиторіях.');

            return Command::SUCCESS;
        }

        if (!MongoManagerFactory::isServerReachable($uri)) {
            $io->warning(\sprintf('MongoDB за адресою %s недоступна — індекси не створено.', $uri));

            return Command::SUCCESS;
        }

        $manager = MongoManagerFactory::create($uri);

        foreach (self::indexDefinitions() as $collection => $indexes) {
            $manager->executeCommand($this->database, new \MongoDB\Driver\Command([
                'createIndexes' => $collection,
                'indexes' => $indexes,
            ]));

            $io->writeln(\sprintf('  <info>✓</info> %s.%s — %d індекс(ів)', $this->database, $collection, \count($indexes)));
        }

        $io->success('Індекси створено.');

        return Command::SUCCESS;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function indexDefinitions(): array
    {
        return [
            // 10.6: unique {login:1}; {supplierId:1} для масових операцій (AUTH-28).
            'partner_accounts' => [
                ['key' => ['login' => 1], 'name' => 'login_unique', 'unique' => true],
                ['key' => ['supplierId' => 1], 'name' => 'supplierId_idx'],
            ],
            // 10.5/10.6: TTL на expiresAt; вибірка для logout-all; пошук за хешем (AUTH-30).
            'refresh_tokens' => [
                ['key' => ['tokenHash' => 1], 'name' => 'tokenHash_unique', 'unique' => true],
                ['key' => ['accountId' => 1, 'revokedAt' => 1], 'name' => 'account_revoked_idx'],
                ['key' => ['sid' => 1], 'name' => 'sid_idx'],
                ['key' => ['expiresAt' => 1], 'name' => 'expiresAt_ttl', 'expireAfterSeconds' => 0],
            ],
            // DATA-20: {login:1, at:-1} для лічильника блокувань; TTL 30 днів на at.
            'login_attempts' => [
                ['key' => ['login' => 1, 'at' => -1], 'name' => 'login_at_idx'],
                ['key' => ['at' => 1], 'name' => 'at_ttl', 'expireAfterSeconds' => 2592000],
            ],
        ];
    }
}
