<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Booking\VehicleSnapshot;
use App\Domain\Exception\ValidationFailedException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Типізований доступ до JSON-тіла запиту з українськими повідомленнями
 * про помилки. Усі помилки формату — 422 VALIDATION_FAILED.
 */
final readonly class RequestPayload
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

        if ('' === $content) {
            return new self([]);
        }

        try {
            $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationFailedException('Тіло запиту має бути коректним JSON');
        }

        if (!\is_array($decoded)) {
            throw new ValidationFailedException('Тіло запиту має бути JSON-обʼєктом');
        }

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
            throw new ValidationFailedException(\sprintf('Поле «%s» обовʼязкове', $key));
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new ValidationFailedException(\sprintf('Поле «%s» має бути рядком', $key));
        }

        return '' === trim($value) ? null : $value;
    }

    public function requiredInt(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            throw new ValidationFailedException(\sprintf('Поле «%s» має бути цілим числом', $key));
        }

        return (int) $value;
    }

    public function optionalInt(string $key): ?int
    {
        return \array_key_exists($key, $this->data) && null !== $this->data[$key]
            ? $this->requiredInt($key)
            : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? $default;

        return \is_bool($value) ? $value : \in_array($value, ['true', '1', 1], true);
    }

    public function requiredDateTime(string $key): DateTimeImmutable
    {
        $value = $this->requiredString($key);

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ValidationFailedException(\sprintf('Поле «%s» має містити дату в форматі ISO 8601', $key));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function object(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            throw new ValidationFailedException(\sprintf('Поле «%s» має бути обʼєктом', $key));
        }

        return $value;
    }

    /** Снапшот авто із поля `vehicle` (розділ 6.4). */
    public function vehicle(string $key = 'vehicle'): VehicleSnapshot
    {
        $vehicle = self::fromArray($this->object($key));

        $weight = $vehicle->data['weightTons'] ?? null;

        if (!is_numeric($weight)) {
            throw new ValidationFailedException('Поле «vehicle.weightTons» обовʼязкове');
        }

        return new VehicleSnapshot(
            plateNumber: $vehicle->requiredString('plateNumber'),
            weightTons: (float) $weight,
            brand: $vehicle->optionalString('brand'),
        );
    }
}
