<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Безпечне читання JSON-тіла запиту.
 *
 * AUTH-61: тіла запитів логіну/зміни пароля не логуються — цей клас не пише
 * жодних логів і не кидає винятків із вмістом пароля.
 */
final readonly class JsonRequestPayload
{
    /** @param array<string, mixed> $data */
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
        } catch (\JsonException) {
            throw new ValidationException(['Тіло запиту має бути коректним JSON.']);
        }

        if (!\is_array($decoded)) {
            throw new ValidationException(['Тіло запиту має бути JSON-обʼєктом.']);
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function requiredString(string $field): string
    {
        $value = $this->data[$field] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw ValidationException::missingField($field);
        }

        return $value;
    }

    public function optionalString(string $field): ?string
    {
        $value = $this->data[$field] ?? null;

        return \is_string($value) && '' !== trim($value) ? $value : null;
    }

    public function boolean(string $field, bool $default): bool
    {
        $value = $this->data[$field] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }
}
