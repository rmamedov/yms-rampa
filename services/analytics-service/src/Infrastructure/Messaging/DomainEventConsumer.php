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
 * Транспорт (RabbitMQ через Symfony Messenger) підключається зовні: сюди
 * потрапляє вже готове тіло повідомлення. Клас навмисно не залежить від
 * Messenger, тому той самий шлях використовують консольна команда
 * analytics:events:ingest та інтеграційні тести.
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
        return $this->projector->project(DomainEvent::fromArray($decoded));
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
