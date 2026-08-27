<?php

declare(strict_types=1);

namespace App\Infrastructure\InMemory;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Transport\NotificationTransport;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportException;
use App\Domain\Transport\TransportReceipt;

/**
 * Транспорт у памʼяті: запамʼятовує все, що «відправив».
 *
 * Дає змогу тестувати ретраї (NOT-04) без реального провайдера:
 * `failNextTimes()` імітує недоступність, `failAlways()` — повний відмов.
 */
final class InMemoryTransport implements NotificationTransport
{
    /** @var list<OutgoingMessage> */
    private array $sent = [];

    private int $failuresLeft = 0;

    private bool $failAlways = false;

    private bool $permanentFailure = false;

    private int $sequence = 0;

    /** @param list<NotificationChannel> $channels */
    public function __construct(
        private readonly array $channels = [NotificationChannel::Sms, NotificationChannel::Email],
        private readonly string $name = 'in-memory',
    ) {
    }

    public function supports(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels, true);
    }

    public function send(OutgoingMessage $message): TransportReceipt
    {
        if ($this->failAlways || $this->failuresLeft > 0) {
            if (!$this->failAlways) {
                --$this->failuresLeft;
            }

            throw $this->permanentFailure
                ? TransportException::permanent('Провайдер відхилив повідомлення: невалідний отримувач.')
                : new TransportException('Провайдер недоступний: таймаут зʼєднання.');
        }

        $this->sent[] = $message;

        return new TransportReceipt(
            providerMessageId: \sprintf('%s-%06d', $this->name, ++$this->sequence),
            provider: $this->name,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Наступні $times спроб завершаться технічним збоєм. */
    public function failNextTimes(int $times): void
    {
        $this->failuresLeft = $times;
        $this->failAlways = false;
        $this->permanentFailure = false;
    }

    public function failAlways(): void
    {
        $this->failAlways = true;
        $this->permanentFailure = false;
    }

    /** Невиправна помилка — ретраї не робляться. */
    public function failPermanently(): void
    {
        $this->failAlways = true;
        $this->permanentFailure = true;
    }

    public function recover(): void
    {
        $this->failAlways = false;
        $this->failuresLeft = 0;
        $this->permanentFailure = false;
    }

    /** @return list<OutgoingMessage> */
    public function sentMessages(): array
    {
        return $this->sent;
    }

    public function lastMessage(): ?OutgoingMessage
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }

    public function sentCount(): int
    {
        return \count($this->sent);
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->recover();
    }
}
