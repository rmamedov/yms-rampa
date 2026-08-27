<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічна доменна подія (реєстр розділу 2, DATA-16).
 *
 * partner-service публікує рівно дві події реєстру: `DriverCreated`
 * і `SupplierSuspended`. Вигадувати нові типи подій заборонено.
 */
interface DomainEvent
{
    /** Назва події з канонічного реєстру, напр. DriverCreated. */
    public function eventType(): string;

    /** Ідентифікатор агрегату, з яким сталася подія. */
    public function aggregateId(): string;

    public function occurredAt(): \DateTimeImmutable;

    /**
     * @return array<string, mixed> тіло події для RabbitMQ
     */
    public function payload(): array;
}
