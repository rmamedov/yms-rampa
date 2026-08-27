<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\PartnerUser\PartnerUserRepository;
use App\Domain\PartnerUser\PartnerUserType;
use App\Domain\Shared\ConflictException;

/**
 * Сховище профілів партнерського контуру в пам'яті.
 *
 * Повторює поведінку unique partial index DATA-17
 * `{phone:1}` з фільтром `{type:"driver", archivedAt:null}`:
 * телефон водія унікальний ГЛОБАЛЬНО, незалежно від постачальника.
 */
final class InMemoryPartnerUserRepository implements PartnerUserRepository
{
    /** @var array<string, PartnerUser> */
    private array $items = [];

    public function save(PartnerUser $user): void
    {
        if ($user->isDriver() && null !== $user->phone() && null === $user->archivedAt()) {
            $clash = $this->findDriverByPhone($user->phone());

            if (null !== $clash && $clash->id() !== $user->id()) {
                throw new ConflictException(
                    'Водій з таким телефоном уже зареєстрований.',
                    'DRIVER_PHONE_DUPLICATE',
                );
            }
        }

        $this->items[$user->id()] = $user;
    }

    public function findById(string $id): ?PartnerUser
    {
        return $this->items[$id] ?? null;
    }

    public function findByAccountId(string $accountId): ?PartnerUser
    {
        foreach ($this->items as $user) {
            if ($user->accountId() === $accountId) {
                return $user;
            }
        }

        return null;
    }

    public function findDriverByPhone(string $phone): ?PartnerUser
    {
        foreach ($this->items as $user) {
            if (!$user->isDriver() || null !== $user->archivedAt()) {
                continue;
            }

            if ($user->phone() === $phone) {
                return $user;
            }
        }

        return null;
    }

    public function listBySupplier(
        string $supplierId,
        ?PartnerUserType $type = null,
        bool $includeInactive = true,
    ): array {
        $found = [];

        foreach ($this->items as $user) {
            if (!$user->belongsTo($supplierId) || null !== $user->archivedAt()) {
                continue;
            }

            if (null !== $type && $user->type() !== $type) {
                continue;
            }

            if (!$includeInactive && !$user->isActive()) {
                continue;
            }

            $found[] = $user;
        }

        usort($found, static fn (PartnerUser $a, PartnerUser $b): int => strcmp($a->fullName(), $b->fullName()));

        return array_values($found);
    }

    public function remove(string $id): void
    {
        unset($this->items[$id]);
    }
}
