<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Reminder\ReminderScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Прогін черги сповіщень.
 *
 * Робить дві речі:
 * 1) ставить у чергу нагадування, час яких настав (NOT-06);
 * 2) повторює спроби відправки, які чекали за розкладом backoff (NOT-04).
 *
 * Запускається cron-джобом раз на хвилину. Саме завдяки цій команді
 * недоступність провайдера не призводить до втрати повідомлень.
 */
#[AsCommand(
    name: 'app:notifications:dispatch-due',
    description: 'Відправляє нагадування і повторює невдалі спроби з черги',
)]
final class DispatchDueNotificationsCommand extends Command
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly ReminderScheduler $reminders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум записів за прогін', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $reminders = $this->reminders->dispatchDue($limit);
        $counters = $this->dispatcher->processDue($limit);

        $io->definitionList(
            ['Нагадувань поставлено в чергу' => (string) $reminders],
            ['Відправлено' => (string) $counters['sent']],
            ['Заплановано повтор' => (string) $counters['retrying']],
            ['Остаточно не доставлено' => (string) $counters['failed']],
            ['Пропущено' => (string) $counters['skipped']],
        );

        return Command::SUCCESS;
    }
}
