<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Transport\SmsProviderInterface;
use App\Domain\Transport\TransportException;

/**
 * SMS-провайдер у памʼяті — підстановка замість TurboSMS/eSputnik
 * у тестах і dev-режимі (NOT-01).
 */
final class InMemorySmsProvider implements SmsProviderInterface
{
    /** @var list<array{phone: string, text: string}> */
    private array $sent = [];

    private int $failuresLeft = 0;

    private int $sequence = 0;

    public function send(string $phone, string $text): string
    {
        if ($this->failuresLeft > 0) {
            --$this->failuresLeft;

            throw new TransportException('SMS-провайдер недоступний (імітація збою).');
        }

        $this->sent[] = ['phone' => $phone, 'text' => $text];

        return \sprintf('in-memory-sms-%06d', ++$this->sequence);
    }

    public function name(): string
    {
        return 'in-memory-sms';
    }

    public function failNextTimes(int $times): void
    {
        $this->failuresLeft = $times;
    }

    /** @return list<array{phone: string, text: string}> */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->failuresLeft = 0;
    }
}
