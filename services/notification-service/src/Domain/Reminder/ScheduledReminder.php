<?php

declare(strict_types=1);

namespace App\Domain\Reminder;

use App\Domain\Exception\DomainException;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;

/**
 * Заплановане нагадування (NOT-06): NOT-T3 за 24 год і NOT-T4 за 2 год.
 *
 * Ставиться при переході бронювання в `booked` і знімається при скасуванні.
 */
final class ScheduledReminder
{
    /** @param array<string, scalar|\Stringable|null> $payload */
    public function __construct(
        private readonly string $id,
        private readonly string $bookingId,
        private readonly NotificationTemplate $template,
        private readonly NotificationChannel $channel,
        private readonly string $recipient,
        private readonly ?string $recipientId,
        private readonly array $payload,
        private readonly \DateTimeImmutable $sendAtUtc,
        private ReminderStatus $status = ReminderStatus::Scheduled,
    ) {
        if (NotificationTemplate::Reminder24h !== $template && NotificationTemplate::Reminder2h !== $template) {
            throw new DomainException(
                'Планувальник нагадувань працює лише з шаблонами NOT-T3 і NOT-T4.',
                'REMINDER_TEMPLATE_INVALID',
                500,
            );
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function bookingId(): string
    {
        return $this->bookingId;
    }

    public function template(): NotificationTemplate
    {
        return $this->template;
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public function recipientId(): ?string
    {
        return $this->recipientId;
    }

    /** @return array<string, scalar|\Stringable|null> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function sendAtUtc(): \DateTimeImmutable
    {
        return $this->sendAtUtc;
    }

    public function status(): ReminderStatus
    {
        return $this->status;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return ReminderStatus::Scheduled === $this->status && $this->sendAtUtc <= $now;
    }

    public function markSent(): void
    {
        $this->status = ReminderStatus::Sent;
    }

    /** NOT-06: скасування бронювання знімає заплановані нагадування. */
    public function cancel(): void
    {
        if (ReminderStatus::Scheduled === $this->status) {
            $this->status = ReminderStatus::Cancelled;
        }
    }
}
