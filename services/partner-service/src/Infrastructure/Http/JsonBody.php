<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір JSON-тіла запиту з типізованим доступом до полів.
 *
 * Свідомо без symfony/serializer і symfony/validator: перевірки бізнес-правил
 * живуть у домені, а тут лише перетворення типів на межі HTTP.
 */
final readonly class JsonBody
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $content = $request->getContent();

        if ('' === trim($content)) {
            return new self([]);
        }

        try {
            $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValidationException(
                \sprintf('Тіло запиту не є коректним JSON: %s', $e->getMessage()),
                'REQUEST_BODY_INVALID',
            );
        }

        if (!\is_array($decoded)) {
            throw new ValidationException('Тіло запиту має бути JSON-об\'єктом.', 'REQUEST_BODY_INVALID');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function requiredString(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new ValidationException(
                \sprintf('Поле «%s» обов\'язкове.', $key),
                'FIELD_REQUIRED',
            );
        }

        return trim($value);
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new ValidationException(
                \sprintf('Поле «%s» має бути рядком.', $key),
                'FIELD_TYPE_INVALID',
            );
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    public function requiredFloat(string $key): float
    {
        $value = $this->data[$key] ?? null;

        if (!is_numeric($value)) {
            throw new ValidationException(
                \sprintf('Поле «%s» має бути числом.', $key),
                'FIELD_TYPE_INVALID',
            );
        }

        return (float) $value;
    }

    public function optionalFloat(string $key): ?float
    {
        if (!$this->has($key) || null === $this->data[$key]) {
            return null;
        }

        return $this->requiredFloat($key);
    }

    public function optionalBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * @return list<mixed>|null
     */
    public function optionalList(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw new ValidationException(
                \sprintf('Поле «%s» має бути масивом.', $key),
                'FIELD_TYPE_INVALID',
            );
        }

        return array_values($value);
    }
}
