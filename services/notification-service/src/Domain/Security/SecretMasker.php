<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Маскування секретів для журналів (NOT-15).
 *
 * Одноразовий пароль водія НІКОЛИ не має потрапляти в лог — ані як окреме
 * поле payload, ані у складі відрендереного тексту SMS. Маскер закриває
 * обидва шляхи: за іменем поля і за значенням у довільному тексті.
 */
final class SecretMasker
{
    public const string MASK = '***';

    /**
     * Імена полів, які маскуються завжди, незалежно від шаблону.
     *
     * @var list<string>
     */
    private const array ALWAYS_SENSITIVE = [
        'password',
        'oneTimePassword',
        'passwordHash',
        'token',
        'accessToken',
        'refreshToken',
        'secret',
        'apiKey',
        'authorization',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $extraSensitiveKeys
     *
     * @return array<string, mixed>
     */
    public function maskArray(array $payload, array $extraSensitiveKeys = []): array
    {
        $sensitive = $this->sensitiveIndex($extraSensitiveKeys);
        $masked = [];

        foreach ($payload as $key => $value) {
            if (isset($sensitive[mb_strtolower((string) $key)])) {
                $masked[$key] = null === $value || '' === $value ? $value : self::MASK;

                continue;
            }

            $masked[$key] = \is_array($value) ? $this->maskArray($value, $extraSensitiveKeys) : $value;
        }

        return $masked;
    }

    /**
     * Витирає з тексту секретні значення, взяті з payload.
     * Використовується перед записом відрендереного SMS у журнал.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $extraSensitiveKeys
     */
    public function maskText(string $text, array $payload, array $extraSensitiveKeys = []): string
    {
        $sensitive = $this->sensitiveIndex($extraSensitiveKeys);
        $values = [];

        foreach ($payload as $key => $value) {
            if (!\is_string($key) || !isset($sensitive[mb_strtolower($key)])) {
                continue;
            }
            if (!\is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            $stringValue = (string) $value;
            // Надто коротке значення замінило б випадкові фрагменти тексту.
            if (mb_strlen($stringValue) >= 3) {
                $values[] = $stringValue;
            }
        }

        return [] === $values ? $text : str_replace($values, self::MASK, $text);
    }

    /**
     * Явне маскування переліку значень (коли ключів немає — лише секрети).
     *
     * @param list<string> $values
     */
    public function maskValues(string $text, array $values): string
    {
        $usable = array_values(array_filter($values, static fn (string $v): bool => mb_strlen($v) >= 3));

        return [] === $usable ? $text : str_replace($usable, self::MASK, $text);
    }

    /**
     * @param list<string> $extra
     *
     * @return array<string, true>
     */
    private function sensitiveIndex(array $extra): array
    {
        $index = [];
        foreach ([...self::ALWAYS_SENSITIVE, ...$extra] as $key) {
            $index[mb_strtolower($key)] = true;
        }

        return $index;
    }
}
