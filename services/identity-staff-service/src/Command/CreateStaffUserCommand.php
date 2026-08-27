<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Identity\Email;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserRepository;
use App\Domain\Password\PasswordHasher;
use App\Domain\Password\PasswordPolicy;
use App\Domain\Shared\Clock;
use App\Domain\Shared\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Створення staff-користувача з консолі (бутстрап першого super_admin,
 * розділ 4.7 — далі користувачі створюються в admin-web).
 *
 * RBAC-04: приймає РІВНО ОДНУ роль; політика паролів AUTH-13 застосовується.
 */
#[AsCommand(
    name: 'yms:staff:user:create',
    description: 'Створює обліковий запис співробітника (staff-контур)',
)]
final class CreateStaffUserCommand extends Command
{
    public function __construct(
        private readonly StaffUserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email співробітника (логін)')
            ->addArgument('password', InputArgument::REQUIRED, 'Пароль (політика AUTH-13)')
            ->addArgument('role', InputArgument::REQUIRED, 'Роль: '.implode(' | ', array_map(
                static fn (Role $role): string => $role->value,
                Role::staffRoles(),
            )))
            ->addOption('store', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Магазин у скоупі (можна вказати кілька разів)', [])
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Повне імʼя', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $roleValue = (string) $input->getArgument('role');
        $role = Role::tryFrom($roleValue);

        if (null === $role) {
            $io->error(\sprintf('Невідома роль "%s".', $roleValue));

            return Command::INVALID;
        }

        try {
            $email = Email::fromString((string) $input->getArgument('email'));
            $password = (string) $input->getArgument('password');
            $fullName = (string) $input->getOption('name');

            if (null !== $this->users->findByEmail($email)) {
                $io->error(\sprintf('Користувач "%s" вже існує.', $email->value));

                return Command::FAILURE;
            }

            $this->passwordPolicy->assertValid($password, $email->value, $fullName);

            /** @var list<string> $storeIds */
            $storeIds = array_values((array) $input->getOption('store'));

            $user = StaffUser::create(
                email: $email,
                passwordHash: $this->hasher->hash($password),
                role: $role,
                storeIds: $storeIds,
                now: $this->clock->now(),
                fullName: $fullName,
            );

            $this->users->save($user);
        } catch (DomainException $exception) {
            $io->error(\sprintf('[%s] %s', $exception->errorCode(), $exception->userMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Створено користувача %s (%s), роль: %s, магазини: %s',
            $user->id(),
            $user->email()->value,
            $user->role()->value,
            [] === $user->storeIds() ? '— (нуль доступу для магазинних ролей)' : implode(', ', $user->storeIds()),
        ));

        return Command::SUCCESS;
    }
}
