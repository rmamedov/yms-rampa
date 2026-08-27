<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Service\DriverService;
use App\Domain\Shared\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення водія з консолі (той самий сценарій, що й SUP-DRV-02/03,
 * але для адміністрування й локальної перевірки).
 *
 * Пароль друкується один раз — так само, як показується в модалці кабінету.
 */
#[AsCommand(
    name: 'app:partner:driver:create',
    description: 'Створює водія постачальника і друкує згенерований пароль (одноразово)',
)]
final class CreateDriverCommand extends Command
{
    public function __construct(private readonly DriverService $drivers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('supplierId', InputArgument::REQUIRED, 'Ідентифікатор постачальника')
            ->addArgument('phone', InputArgument::REQUIRED, 'Телефон водія у будь-якому форматі')
            ->addArgument('firstName', InputArgument::REQUIRED, 'Ім\'я')
            ->addArgument('lastName', InputArgument::REQUIRED, 'Прізвище');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $credentials = $this->drivers->createDriver(
                supplierId: (string) $input->getArgument('supplierId'),
                phone: (string) $input->getArgument('phone'),
                firstName: (string) $input->getArgument('firstName'),
                lastName: (string) $input->getArgument('lastName'),
            );
        } catch (DomainException $e) {
            $io->error(\sprintf('[%s] %s', $e->errorCode(), $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Водія створено: %s', $credentials->driver->id()));
        $io->definitionList(
            ['Логін' => $credentials->login],
            ['Пароль' => $credentials->password],
        );
        $io->note('Запишіть пароль — повторно він не показується.');

        return Command::SUCCESS;
    }
}
