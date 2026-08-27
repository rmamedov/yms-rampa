<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Exception\MalformedEventException;
use App\Domain\Projection\DomainEvent;
use App\Domain\Projection\EventProjector;
use App\Domain\Projection\ProjectionResult;

/**
 * Точка входу потоку доменних подій у read-моделі.
 *
 * Транспорт підключається зовні: сюди потрапляє вже готове тіло повідомлення.
 * Клас навмисно не залежить ні від Messenger, ні від HTTP, тому той самий шлях
 * використовують усі три джерела:
 *   - службовий маршрут POST /internal/v1/analytics/events (релей outbox
 *     booking-service — це те, що працює на стенді);
 *   - консольна команда analytics:events:ingest (backfill з NDJSON);
 *   - майбутній споживач RabbitMQ, коли брокер зʼявиться.
 *
 * Доставка at-least-once безпечна: ідемпотентність гарантує EventProjector.
 */
final readonly class DomainEventConsumer
{
    public function __construct(private EventProjector $projector)
    {
    }

    public function consumeJson(string $json): ProjectionResult
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedEventException(
                'Повідомлення не є коректним JSON: ' . $exception->getMessage(),
            );
        }

        if (!is_array($decoded)) {
            throw new MalformedEventException('Повідомлення має бути JSON-обʼєктом події.');
        }

        /** @var array<string, mixed> $decoded */
        return $this->consumeArray($decoded);
    }

    /**
     * Той самий шлях для транспорту, який тіло вже розібрав (HTTP-маршрут
     * приймає пакет подій одним JSON, і кодувати кожну назад у рядок було б
     * марною роботою).
     *
     * @param array<string, mixed> $event
     */
    public function consumeArray(array $event): ProjectionResult
    {
        return $this->projector->project(DomainEvent::fromArray($event));
    }

    /**
     * @param iterable<string> $lines рядки NDJSON
     *
     * @return list<ProjectionResult>
     */
    public function consumeStream(iterable $lines): array
    {
        $results = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $results[] = $this->consumeJson($line);
        }

        return $results;
    }
}
