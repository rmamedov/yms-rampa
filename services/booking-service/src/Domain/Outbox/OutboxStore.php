<?php

declare(strict_types=1);

namespace App\Domain\Outbox;

use App\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Transactional outbox (DATA-16): подія записується в тій самій операції,
 * що й зміна бронювання, а публікація в RabbitMQ виконується окремим релеєм
 * з семантикою at-least-once.
 */
interface OutboxStore
{
    /**
     * @param list<DomainEvent> $events
     */
    public function append(array $events): void;

    /**
     * Неопубліковані записи в порядку виникнення — черга релея.
     *
     * @return list<OutboxRecord>
     */
    public function pending(int $limit = 100): array;

    public function markPublished(string $recordId, DateTimeImmutable $publishedAt): void;
}
