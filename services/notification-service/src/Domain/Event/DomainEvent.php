<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Канонічна доменна подія YMS «Рампа» (реєстр подій, розділ 2).
 *
 * У notification-service події — це прості DTO спільного контракту:
 * сервіс лише споживає їх із RabbitMQ через Symfony Messenger (NOT-02).
 * Прямі синхронні виклики «відправ SMS» з інших сервісів заборонені.
 */
interface DomainEvent
{
    /** Канонічна назва події, напр. «BookingCreated». */
    public function eventName(): string;

    /** Момент настання події у UTC. */
    public function occurredAt(): \DateTimeImmutable;
}
