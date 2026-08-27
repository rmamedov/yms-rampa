<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Порт публікації доменних подій.
 *
 * DATA-16: у проді запис бізнес-документа і запис у `outbox` виконуються
 * в одній транзакції MongoDB, а релей (Symfony Messenger) публікує подію
 * в RabbitMQ з семантикою at-least-once.
 */
interface EventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
