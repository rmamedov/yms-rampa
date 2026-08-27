<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Публікація доменних подій. Домен не знає про RabbitMQ/Messenger —
 * транспорт підмінюється реалізацією інтерфейсу в інфраструктурі.
 */
interface EventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
