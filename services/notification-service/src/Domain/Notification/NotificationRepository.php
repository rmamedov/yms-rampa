<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Сховище сповіщень.
 *
 * Домен знає лише цей інтерфейс; реалізації — MongoDB (прод) та
 * InMemory (юніт-тести і dev-режим без БД).
 */
interface NotificationRepository
{
    public function save(Notification $notification): void;

    public function find(string $id): ?Notification;

    /**
     * Сповіщення, які чекають чергової спроби відправки (NOT-04).
     *
     * @return list<Notification>
     */
    public function findDue(\DateTimeImmutable $now, int $limit = 100): array;

    /**
     * Усі сповіщення, повʼязані з бронюванням/водієм — для журналу і
     * для скасування нагадувань.
     *
     * @return list<Notification>
     */
    public function findByCorrelationId(string $correlationId): array;

    public function nextIdentity(): string;
}
