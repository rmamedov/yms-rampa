<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Exception\AuthException;
use App\Domain\Provisioning\PartnerAccountProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Перегенерація пароля водія з консолі (AUTH-25).
 *
 * Старий пароль стає недійсним негайно, всі сесії водія відкликаються,
 * новий пароль показується рівно один раз.
 */
#[AsCommand(
    name: 'app:partner-account:regenerate-password',
    description: 'Перегенеровує пароль облікового запису партнерського контуру та відкликає всі його сесії.',
)]
final class RegenerateDriverPasswordCommand extends Command
{
    public function __construct(private readonly PartnerAccountProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('accountId', InputArgument::REQUIRED, 'Ідентифікатор облікового запису (partner_accounts._id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $credentials = $this->provisioner->regeneratePassword((string) $input->getArgument('accountId'));
        } catch (AuthException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Пароль для «%s» перегенеровано, усі сесії відкликано.', $credentials->profile->login));
        $io->warning('Пароль показується один раз:');
        $io->writeln((string) $credentials->passwordPlain);

        return Command::SUCCESS;
    }
}
