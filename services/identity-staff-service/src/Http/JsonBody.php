<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Identity\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Розбір JSON-тіла запиту з валідацією полів (RBAC-33: будь-яка помилка
 * формату — 422 VALIDATION_FAILED у форматі RFC 7807).
 *
 * Окремий обʼєкт, а не хелпери в контролері: розділ «Користувачі» читає
 * з тіла і рядки, і переліки, і булеві прапорці, і робити це «на місці»
 * в кожному методі означало б розмножити ті самі перевірки.
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
            /** @var array<string, mixed> $form */
            $form = $request->request->all();

            return new self($form);
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Тіло запиту має бути коректним JSON.');
        }

        if (!\is_array($decoded)) {
            throw new ValidationException('Тіло запиту має бути JSON-обʼєктом.');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    /**
     * PATCH застосовує ЛИШЕ передані поля, тому «поле є, але порожнє»
     * і «поля немає» — різні випадки.
     */
    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->data);
    }

    public function requiredString(string $field): string
    {
        $value = $this->optionalString($field);

        if (null === $value || '' === $value) {
            throw self::missing($field);
        }

        return $value;
    }

    public function optionalString(string $field): ?string
    {
        $value = $this->data[$field] ?? null;

        if (null === $value || \is_array($value)) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Пароль НЕ обрізається пробілами: пробіл — валідний символ пароля.
     */
    public function optionalRaw(string $field): ?string
    {
        $value = $this->data[$field] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Перелік рядків БЕЗ дедуплікації: повтор у переліку — це факт запиту,
     * і рішення, чи він припустимий, ухвалює домен. Для `storeIds` дублікати
     * прибирає StaffUser::normalizeStoreIds, а для `roles` повторення тієї
     * самої ролі — це спроба призначити другу (RBAC-27.1), яку не можна
     * мовчки «виправити».
     *
     * @return list<string>|null null — поля немає, тобто змінювати нічого
     */
    public function optionalStringList(string $field): ?array
    {
        if (!$this->has($field)) {
            return null;
        }

        $value = $this->data[$field];

        if (null === $value) {
            return [];
        }

        if (!\is_array($value)) {
            throw new ValidationException(
                \sprintf('Поле "%s" має бути переліком.', $field),
                [\sprintf('Поле "%s" очікує масив значень', $field)],
            );
        }

        $result = [];

        foreach ($value as $item) {
            if (\is_array($item)) {
                throw new ValidationException(
                    \sprintf('Поле "%s" має бути переліком рядків.', $field),
                    [\sprintf('Поле "%s" містить вкладений масив', $field)],
                );
            }

            $trimmed = trim((string) $item);

            if ('' !== $trimmed) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    private static function missing(string $field): ValidationException
    {
        return new ValidationException(
            \sprintf('Поле "%s" обовʼязкове.', $field),
            [\sprintf('Не заповнено поле "%s"', $field)],
        );
    }
}
