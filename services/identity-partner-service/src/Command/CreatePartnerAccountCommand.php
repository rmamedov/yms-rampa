<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Account\PartnerRole;
use App\Domain\Exception\AuthException;
use App\Domain\Provisioning\CreatePartnerAccount;
use App\Domain\Provisioning\PartnerAccountProvisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Консольний двійник внутрішнього ендпоїнта створення акаунта (AUTH-20,
 * AUTH-23, AUTH-24): та сама доменна команда CreatePartnerAccount і той самий
 * PartnerAccountProvisioner, що й у HTTP.
 */
#[AsCommand(
    name: 'app:partner-account:create',
    description: 'Створює обліковий запис партнерського контуру (постачальник або водій).',
)]
final class CreatePartnerAccountCommand extends Command
{
    public function __construct(private readonly PartnerAccountProvisioner $provisioner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login', InputArgument::REQUIRED, 'Email постачальника або телефон водія (у будь-якому форматі — буде нормалізовано)')
            ->addArgument('role', InputArgument::REQUIRED, 'Роль: supplier_admin | supplier_operator | driver')
            ->addArgument('supplierId', InputArgument::REQUIRED, 'Ідентифікатор постачальника')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Пароль; якщо не вказано — буде згенеровано (AUTH-24)')
            ->addOption('driver-profile-id', null, InputOption::VALUE_REQUIRED, 'Посилання на partner_users._id для водія')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Створити деактивованим');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawRole = (string) $input->getArgument('role');
        $role = PartnerRole::tryFrom($rawRole);

        if (null === $role) {
            $io->error(\sprintf('Невідома роль «%s». Дозволені: supplier_admin, supplier_operator, driver.', $rawRole));

            return Command::INVALID;
        }

        $password = $input->getOption('password');
        $driverProfileId = $input->getOption('driver-profile-id');

        try {
            $credentials = $this->provisioner->create(new CreatePartnerAccount(
                login: (string) $input->getArgument('login'),
                role: $role,
                supplierId: (string) $input->getArgument('supplierId'),
                passwordPlain: \is_string($password) ? $password : null,
                driverProfileId: \is_string($driverProfileId) ? $driverProfileId : null,
                active: !$input->getOption('inactive'),
            ));
        } catch (AuthException $exception) {
            $io->error($exception->getMessage());

            foreach ($exception->extensions()['violations'] ?? [] as $violation) {
                $io->writeln(' - '.(string) $violation);
            }

            return Command::FAILURE;
        }

        $io->success('Обліковий запис створено.');
        $io->definitionList(
            ['accountId' => $credentials->profile->accountId],
            ['login' => $credentials->profile->login],
            ['role' => $credentials->profile->role->value],
            ['supplierId' => $credentials->profile->supplierId],
        );

        if ($credentials->passwordGenerated) {
            $io->warning('Пароль показується один раз — збережіть його зараз:');
            $io->writeln((string) $credentials->passwordPlain);
        }

        return Command::SUCCESS;
    }
}
