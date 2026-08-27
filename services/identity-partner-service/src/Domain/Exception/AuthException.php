<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Базова доменна помилка автентифікації партнерського контуру.
 *
 * Кожна помилка несе канонічний код (розділ 3.7), HTTP-статус і текст
 * українською, який показується користувачу. AUTH-53: тексти не розкривають
 * існування облікового запису, стан акаунта чи причину «логін чи пароль» —
 * технічні деталі лишаються в логах.
 */
abstract class AuthException extends \RuntimeException
{
    /** Канонічний код помилки (розділ 3.7), потрапляє в поле `code` RFC 7807. */
    abstract public function errorCode(): string;

    /** HTTP-статус відповіді. */
    abstract public function httpStatus(): int;

    /** Заголовок problem+json (title). */
    public function title(): string
    {
        return 'Помилка автентифікації';
    }

    /**
     * Додаткові розширення problem+json (наприклад, перелік порушених правил
     * пароля або Retry-After).
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return [];
    }
}
