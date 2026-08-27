<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use Psr\Log\AbstractLogger;

/**
 * PSR-логер у памʼяті.
 *
 * Потрібен, щоб перевірити правило безпеки NOT-15: пароль водія не має
 * зʼявлятися в журналі — ані в повідомленні, ані в контексті.
 */
final class InMemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Увесь журнал одним рядком — зручно шукати витік секрету.
     */
    public function dump(): string
    {
        $parts = [];

        foreach ($this->records as $record) {
            $parts[] = $record['level'].' '.$record['message'].' '
                .json_encode($record['context'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return implode("\n", $parts);
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
