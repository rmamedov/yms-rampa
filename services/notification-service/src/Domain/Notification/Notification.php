<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Exception\DomainException;
use App\Domain\Security\SecretMasker;

/**
 * Одне сповіщення — одиниця обліку доставки (NOT-03).
 *
 * Життєвий цикл: queued → sent → delivered / failed / expired.
 * Кожна невдала спроба збільшує лічильник `attempts` і планує наступну
 * спробу (NOT-04); стан завжди зберігається, тому недоступність провайдера
 * не призводить до втрати повідомлення.
 */
final class Notification
{
    /** @param array<string, scalar|\Stringable|null> $payload */
    private function __construct(
        private readonly string $id,
        private readonly NotificationChannel $channel,
        private readonly string $recipient,
        private readonly NotificationTemplate $template,
        private array $payload,
        private readonly \DateTimeImmutable $createdAt,
        private NotificationStatus $status,
        private int $attempts,
        private ?\DateTimeImmutable $nextAttemptAt,
        private ?\DateTimeImmutable $lastAttemptAt,
        private ?\DateTimeImmutable $sentAt,
        private ?string $error,
        private ?string $providerMessageId,
        private readonly ?string $correlationId,
        private readonly ?string $recipientId,
        private readonly ?string $fallbackRecipient,
        private bool $fallbackSpawned,
    ) {
    }

    /**
     * Створює сповіщення в статусі queued, готове до першої спроби.
     *
     * @param array<string, scalar|\Stringable|null> $payload
     */
    public static function queue(
        string $id,
        NotificationChannel $channel,
        string $recipient,
        NotificationTemplate $template,
        array $payload,
        \DateTimeImmutable $now,
        ?string $correlationId = null,
        ?string $recipientId = null,
        ?string $fallbackRecipient = null,
    ): self {
        if ('' === trim($recipient)) {
            throw new DomainException(
                'Не вказано отримувача сповіщення.',
                'NOTIFICATION_RECIPIENT_REQUIRED',
            );
        }

        return new self(
            id: $id,
            channel: $channel,
            recipient: trim($recipient),
            template: $template,
            payload: $payload,
            createdAt: $now,
            status: NotificationStatus::Queued,
            attempts: 0,
            nextAttemptAt: $now,
            lastAttemptAt: null,
            sentAt: null,
            error: null,
            providerMessageId: null,
            correlationId: $correlationId,
            recipientId: $recipientId,
            fallbackRecipient: $fallbackRecipient,
            fallbackSpawned: false,
        );
    }

    /**
     * Відновлення агрегата зі сховища.
     *
     * @param array<string, scalar|\Stringable|null> $payload
     */
    public static function restore(
        string $id,
        NotificationChannel $channel,
        string $recipient,
        NotificationTemplate $template,
        array $payload,
        \DateTimeImmutable $createdAt,
        NotificationStatus $status,
        int $attempts,
        ?\DateTimeImmutable $nextAttemptAt,
        ?\DateTimeImmutable $lastAttemptAt,
        ?\DateTimeImmutable $sentAt,
        ?string $error,
        ?string $providerMessageId,
        ?string $correlationId,
        ?string $recipientId,
        ?string $fallbackRecipient,
        bool $fallbackSpawned,
    ): self {
        return new self(
            id: $id,
            channel: $channel,
            recipient: $recipient,
            template: $template,
            payload: $payload,
            createdAt: $createdAt,
            status: $status,
            attempts: $attempts,
            nextAttemptAt: $nextAttemptAt,
            lastAttemptAt: $lastAttemptAt,
            sentAt: $sentAt,
            error: $error,
            providerMessageId: $providerMessageId,
            correlationId: $correlationId,
            recipientId: $recipientId,
            fallbackRecipient: $fallbackRecipient,
            fallbackSpawned: $fallbackSpawned,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function template(): NotificationTemplate
    {
        return $this->template;
    }

    /** @return array<string, scalar|\Stringable|null> */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Копія payload, придатна для журналів: секрети замінені на маску (NOT-15).
     *
     * @return array<string, scalar|\Stringable|null>
     */
    public function maskedPayload(SecretMasker $masker): array
    {
        return $masker->maskArray($this->payload, $this->template->sensitiveVariables());
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function nextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function lastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function sentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function providerMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function recipientId(): ?string
    {
        return $this->recipientId;
    }

    public function fallbackRecipient(): ?string
    {
        return $this->fallbackRecipient;
    }

    public function fallbackSpawned(): bool
    {
        return $this->fallbackSpawned;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Чи настав час чергової спроби. */
    public function isDue(\DateTimeImmutable $now): bool
    {
        return NotificationStatus::Queued === $this->status
            && null !== $this->nextAttemptAt
            && $this->nextAttemptAt <= $now;
    }

    /** Успішна передача провайдеру. */
    public function markSent(\DateTimeImmutable $at, ?string $providerMessageId = null): void
    {
        $this->assertNotFinal();
        $this->status = NotificationStatus::Sent;
        $this->attempts = $this->attempts + 1;
        $this->lastAttemptAt = $at;
        $this->sentAt = $at;
        $this->nextAttemptAt = null;
        $this->error = null;
        $this->providerMessageId = $providerMessageId;
    }

    /** Delivery-report провайдера підтвердив доставку (NOT-03). */
    public function markDelivered(): void
    {
        if (NotificationStatus::Sent !== $this->status) {
            throw new DomainException(
                'Позначити доставленим можна лише сповіщення у статусі sent.',
                'NOTIFICATION_INVALID_TRANSITION',
                409,
            );
        }
        $this->status = NotificationStatus::Delivered;
    }

    /**
     * Технічний збій провайдера: спробу зараховано, наступна — за розкладом
     * backoff (NOT-04). Сповіщення лишається в черзі.
     */
    public function registerFailedAttempt(string $error, \DateTimeImmutable $at, \DateTimeImmutable $nextAttemptAt): void
    {
        $this->assertNotFinal();
        $this->status = NotificationStatus::Queued;
        $this->attempts = $this->attempts + 1;
        $this->lastAttemptAt = $at;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->error = $error;
    }

    /** Спроби вичерпані — остаточна невдача (NOT-04). */
    public function markFailed(string $error, \DateTimeImmutable $at): void
    {
        $this->assertNotFinal();
        $this->status = NotificationStatus::Failed;
        $this->attempts = $this->attempts + 1;
        $this->lastAttemptAt = $at;
        $this->nextAttemptAt = null;
        $this->error = $error;
    }

    /**
     * Сповіщення втратило актуальність (наприклад, нагадування, час якого
     * минув) — відправляти вже не треба.
     */
    public function markExpired(string $reason): void
    {
        $this->assertNotFinal();
        $this->status = NotificationStatus::Expired;
        $this->nextAttemptAt = null;
        $this->error = $reason;
    }

    public function markFallbackSpawned(): void
    {
        $this->fallbackSpawned = true;
    }

    /**
     * NOT-15: одноразовий пароль водія не персиститься після відправки.
     * Викликається одразу після успішної передачі провайдеру.
     */
    public function forgetSecrets(): void
    {
        foreach ($this->template->sensitiveVariables() as $name) {
            if (\array_key_exists($name, $this->payload)) {
                $this->payload[$name] = null;
            }
        }
    }

    private function assertNotFinal(): void
    {
        if ($this->status->isFinal()) {
            throw new DomainException(
                \sprintf('Сповіщення вже у термінальному статусі «%s» і не може бути змінене.', $this->status->value),
                'NOTIFICATION_INVALID_TRANSITION',
                409,
            );
        }
    }
}
