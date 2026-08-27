<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічна доменна подія YMS «Рампа» (розділ 2). Нових імен подій сервіс не вигадує:
 * store-service публікує лише BranchSynced, StoreConfigChanged і SlotReleased.
 */
interface DomainEvent
{
    /** Канонічна назва події, напр. «BranchSynced». */
    public function name(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    public function occurredAt(): \DateTimeImmutable;
}
