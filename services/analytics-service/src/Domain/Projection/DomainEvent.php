<?php

declare(strict_types=1);

namespace App\Domain\Projection;

use App\Domain\Exception\MalformedEventException;

/**
 * Конверт доменної події з RabbitMQ.
 *
 * eventId — ключ ідемпотентності: брокер гарантує at-least-once доставку,
 * тому повторна доставка того самого eventId не повинна змінювати read-модель.
 */
final readonly class DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public DomainEventName $name,
        public \DateTimeImmutable $occurredAt,
        public array $payload = [],
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $eventId = $raw['eventId'] ?? null;
        if (!is_string($eventId) || $eventId === '') {
            throw new MalformedEventException('Подія без обовʼязкового поля eventId.');
        }

        $rawName = $raw['name'] ?? $raw['event'] ?? $raw['type'] ?? null;
        if (!is_string($rawName)) {
            throw new MalformedEventException('Подія без назви (поле name).');
        }

        $name = DomainEventName::tryFrom($rawName);
        if ($name === null) {
            throw new MalformedEventException(sprintf('Невідома доменна подія «%s».', $rawName), 'EVENT_UNKNOWN_NAME');
        }

        $occurredAt = self::parseDate($raw['occurredAt'] ?? null);
        if ($occurredAt === null) {
            throw new MalformedEventException('Подія без коректного поля occurredAt.');
        }

        $payload = $raw['payload'] ?? [];
        if (!is_array($payload)) {
            throw new MalformedEventException('Поле payload має бути обʼєктом.');
        }

        /** @var array<string, mixed> $payload */
        return new self($eventId, $name, $occurredAt, $payload);
    }

    public function requiredString(string $key): string
    {
        $value = $this->payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new MalformedEventException(sprintf(
                'Подія %s: відсутнє обовʼязкове поле «%s».',
                $this->name->value,
                $key,
            ));
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function requiredInt(string $key): int
    {
        $value = $this->payload[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new MalformedEventException(sprintf(
            'Подія %s: поле «%s» має бути цілим числом.',
            $this->name->value,
            $key,
        ));
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->payload[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->payload[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }

        return $default;
    }

    public function requiredDate(string $key): \DateTimeImmutable
    {
        $date = self::parseDate($this->payload[$key] ?? null);
        if ($date === null) {
            throw new MalformedEventException(sprintf(
                'Подія %s: поле «%s» містить некоректну дату.',
                $this->name->value,
                $key,
            ));
        }

        return $date;
    }

    /** Дата з payload або, якщо її немає, момент публікації події. */
    public function dateOr(string $key, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        return self::parseDate($this->payload[$key] ?? null) ?? $fallback;
    }

    public function optionalDate(string $key): ?\DateTimeImmutable
    {
        return self::parseDate($this->payload[$key] ?? null);
    }

    /** Усі дати нормалізуються до UTC (конвенція зберігання часу). */
    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
