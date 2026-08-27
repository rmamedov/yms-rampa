<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Identity\Permission;
use App\Domain\Identity\PermissionMatrix;
use App\Domain\Identity\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Друк матриці «ролі × права» (розділ 4.4).
 *
 * RBAC-05: команда друкує версію матриці — моніторинг порівнює версії
 * між мікросервісами і фіксує розбіжність як інцидент конфігурації.
 */
#[AsCommand(
    name: 'yms:rbac:matrix',
    description: 'Друкує матрицю «ролі × права» та її версію (RBAC-05, RBAC-10)',
)]
final class DumpPermissionMatrixCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Матриця RBAC, версія %s', PermissionMatrix::VERSION));

        $headers = ['Право'];
        foreach (Role::cases() as $role) {
            $headers[] = $role->value;
        }

        $rows = [];
        foreach (Permission::cases() as $permission) {
            $row = [$permission->value];
            foreach (PermissionMatrix::rowOf($permission) as $symbol) {
                $row[] = $symbol;
            }
            $rows[] = $row;
        }

        $io->table($headers, $rows);
        $io->writeln('Легенда: ✓ — повне право, S — у межах скоупа, P — лише публічні атрибути, — — заборонено.');

        return Command::SUCCESS;
    }
}
