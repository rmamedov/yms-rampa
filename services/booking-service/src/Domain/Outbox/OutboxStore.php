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
     * Черга релея: записи, які ще не опубліковані І не в карантині,
     * у порядку виникнення.
     *
     * @return list<OutboxRecord>
     */
    public function pending(int $limit = 100): array;

    /** Запис прийнято споживачем — прибираємо з черги. */
    public function markPublished(string $recordId, DateTimeImmutable $publishedAt): void;

    /**
     * Запис у карантин: споживач його НЕ прийняв.
     *
     * Не видаляє і не публікує — лише прибирає з черги, щоб один непридатний
     * запис не блокував решту, і зберігає причину та лічильник спроб. Після
     * виправлення payload такі записи повертає в чергу requeueFailed().
     */
    public function markFailed(string $recordId, string $reason, DateTimeImmutable $failedAt): void;

    /**
     * Записи в карантині — для звіту й розбору.
     *
     * @return list<OutboxRecord>
     */
    public function quarantined(int $limit = 100): array;

    public function countQuarantined(): int;

    /**
     * Повернути карантин у чергу (після виправлення формату подій).
     * Лічильник спроб зберігається — видно, скільки разів запис уже пробували.
     *
     * @return int скільки записів повернено
     */
    public function requeueFailed(): int;
}
