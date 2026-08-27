<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Shared\ValidationException;

/**
 * Типобезпечне читання JSON-тіла запиту. Замінює symfony/validator, який не входить
 * до складу цього мікросервісу: доменні правила перевіряються в самих агрегатах,
 * а тут — лише базова типізація вхідних даних (UI-04).
 */
final readonly class Payload
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public static function fromJson(?string $json): self
    {
        if (null === $json || '' === trim($json)) {
            return new self([]);
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException(
                'Тіло запиту не є валідним JSON: '.$e->getMessage(),
                'INVALID_JSON',
            );
        }

        if (!\is_array($decoded)) {
            throw new ValidationException('Тіло запиту має бути JSON-обʼєктом', 'INVALID_JSON');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->data[$key];

        if (null === $value) {
            return null;
        }

        if (!\is_string($value) && !is_numeric($value)) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути рядком', $key));
        }

        return (string) $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->string($key);

        if (null === $value || '' === trim($value)) {
            throw ValidationException::field($key, \sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        if (!$this->has($key) || null === $this->data[$key]) {
            return $default;
        }

        $value = $this->data[$key];

        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути цілим числом', $key));
        }

        return (int) $value;
    }

    public function requireInt(string $key): int
    {
        $value = $this->int($key);

        if (null === $value) {
            throw ValidationException::field($key, \sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        if (!$this->has($key) || null === $this->data[$key]) {
            return $default;
        }

        $value = $this->data[$key];

        if (!is_numeric($value)) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути числом', $key));
        }

        return (float) $value;
    }

    public function requireFloat(string $key): float
    {
        $value = $this->float($key);

        if (null === $value) {
            throw ValidationException::field($key, \sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        if (!$this->has($key) || null === $this->data[$key]) {
            return $default;
        }

        $value = $this->data[$key];

        if (\is_bool($value)) {
            return $value;
        }

        if (\in_array($value, ['true', '1', 1], true)) {
            return true;
        }

        if (\in_array($value, ['false', '0', 0], true)) {
            return false;
        }

        throw ValidationException::field($key, \sprintf('Поле «%s» має бути булевим', $key));
    }

    public function requireBool(string $key): bool
    {
        $value = $this->bool($key);

        if (null === $value) {
            throw ValidationException::field($key, \sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function objectList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!\is_array($value)) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути масивом', $key));
        }

        $result = [];

        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw ValidationException::field($key, \sprintf('Кожен елемент «%s» має бути обʼєктом', $key));
            }

            /** @var array<string, mixed> $item */
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (\is_string($value)) {
            $value = '' === trim($value) ? [] : explode(',', $value);
        }

        if (!\is_array($value)) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути масивом рядків', $key));
        }

        $result = [];

        foreach ($value as $item) {
            if (!\is_string($item) && !is_numeric($item)) {
                throw ValidationException::field($key, \sprintf('Кожен елемент «%s» має бути рядком', $key));
            }

            $trimmed = trim((string) $item);

            if ('' !== $trimmed) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    public function dateTime(string $key, ?\DateTimeImmutable $default = null): ?\DateTimeImmutable
    {
        $value = $this->string($key);

        if (null === $value || '' === trim($value)) {
            return $default;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ValidationException::field($key, \sprintf('Поле «%s» має бути датою у форматі ISO 8601', $key));
        }
    }

    public function requireDateTime(string $key): \DateTimeImmutable
    {
        $value = $this->dateTime($key);

        if (!$value instanceof \DateTimeImmutable) {
            throw ValidationException::field($key, \sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
