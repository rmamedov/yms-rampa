<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Порушення інваріанта стану → HTTP 409 (DATA-08, SYNC-02/INT-05, STC-42).
 */
final class ConflictException extends DomainException
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function title(): string
    {
        return 'Конфлікт стану';
    }

    public static function storeNotConfigured(string $message): self
    {
        return new self($message, 'STORE_NOT_CONFIGURED');
    }

    public static function syncAlreadyRunning(): self
    {
        return new self('Синхронізація вже виконується', 'SYNC_ALREADY_RUNNING');
    }

    public static function reservedRuleOverlap(): self
    {
        return new self(
            'Перетин двох правил резерву на один слот заборонений',
            'RESERVED_RULE_OVERLAP',
        );
    }
}
