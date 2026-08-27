<?php

declare(strict_types=1);

namespace App\Domain\Reminder;

use App\Domain\Clock\Clock;
use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Time\KyivTime;

/**
 * Планувальник нагадувань NOT-T3 (за 24 год) і NOT-T4 (за 2 год), NOT-06.
 *
 * Правила:
 * - нагадування ставляться при переході бронювання в `booked`;
 * - якщо до слота лишилося менше, ніж інтервал нагадування, воно не
 *   ставиться взагалі (статус `skipped`);
 * - скасування бронювання знімає всі його заплановані нагадування;
 * - при перенесенні слота нагадування старого бронювання знімаються,
 *   а нові плануються від слота нового бронювання пари.
 *
 * Дати у підстановках форматуються в Europe/Kyiv.
 */
final readonly class ReminderScheduler
{
    private const int HOURS_24 = 86400;
    private const int HOURS_2 = 7200;

    public function __construct(
        private ScheduledReminderRepository $reminders,
        private NotificationDispatcher $dispatcher,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<ScheduledReminder> фактично поставлені нагадування
     */
    public function scheduleForBooking(ReminderPlan $plan): array
    {
        $now = $this->clock->now();
        $created = [];

        $date = KyivTime::formatDate($plan->slotStartUtc);
        $time = KyivTime::formatTime($plan->slotStartUtc);

        $payload24 = [
            'date' => $date,
            'time' => $time,
            'externalId' => $plan->storeExternalId,
            'address' => $plan->address,
            'rampNumber' => $plan->rampNumber,
        ];
        $payload2 = [
            'time' => $time,
            'externalId' => $plan->storeExternalId,
            'address' => $plan->address,
            'rampNumber' => $plan->rampNumber,
        ];

        // NOT-T3: SMS водію + e-mail постачальнику.
        $sendAt24 = $plan->slotStartUtc->sub(new \DateInterval('PT'.self::HOURS_24.'S'));
        if ($sendAt24 > $now) {
            if ($this->isUsable($plan->driverPhone)) {
                $created[] = $this->persist(
                    $plan,
                    NotificationTemplate::Reminder24h,
                    NotificationChannel::Sms,
                    (string) $plan->driverPhone,
                    $plan->driverId,
                    $payload24,
                    $sendAt24,
                );
            }
            if ($this->isUsable($plan->supplierEmail)) {
                $created[] = $this->persist(
                    $plan,
                    NotificationTemplate::Reminder24h,
                    NotificationChannel::Email,
                    (string) $plan->supplierEmail,
                    $plan->supplierId,
                    $payload24,
                    $sendAt24,
                );
            }
        }

        // NOT-T4: лише SMS водію.
        $sendAt2 = $plan->slotStartUtc->sub(new \DateInterval('PT'.self::HOURS_2.'S'));
        if ($sendAt2 > $now && $this->isUsable($plan->driverPhone)) {
            $created[] = $this->persist(
                $plan,
                NotificationTemplate::Reminder2h,
                NotificationChannel::Sms,
                (string) $plan->driverPhone,
                $plan->driverId,
                $payload2,
                $sendAt2,
            );
        }

        return $created;
    }

    /**
     * NOT-06: скасування бронювання знімає заплановані нагадування.
     *
     * @return int кількість знятих нагадувань
     */
    public function cancelForBooking(string $bookingId): int
    {
        $cancelled = 0;

        foreach ($this->reminders->findByBookingId($bookingId) as $reminder) {
            if (ReminderStatus::Scheduled !== $reminder->status()) {
                continue;
            }
            $reminder->cancel();
            $this->reminders->save($reminder);
            ++$cancelled;
        }

        return $cancelled;
    }

    /**
     * Ставить у чергу відправки всі нагадування, час яких настав.
     *
     * @return int кількість оброблених нагадувань
     */
    public function dispatchDue(int $limit = 100): int
    {
        $processed = 0;

        foreach ($this->reminders->findDue($this->clock->now(), $limit) as $reminder) {
            $this->dispatcher->send(new NotificationRequest(
                template: $reminder->template(),
                channel: $reminder->channel(),
                recipient: $reminder->recipient(),
                payload: $reminder->payload(),
                correlationId: $reminder->bookingId(),
                recipientId: $reminder->recipientId(),
            ));

            $reminder->markSent();
            $this->reminders->save($reminder);
            ++$processed;
        }

        return $processed;
    }

    /** @param array<string, scalar|\Stringable|null> $payload */
    private function persist(
        ReminderPlan $plan,
        NotificationTemplate $template,
        NotificationChannel $channel,
        string $recipient,
        ?string $recipientId,
        array $payload,
        \DateTimeImmutable $sendAtUtc,
    ): ScheduledReminder {
        $reminder = new ScheduledReminder(
            id: $this->reminders->nextIdentity(),
            bookingId: $plan->bookingId,
            template: $template,
            channel: $channel,
            recipient: $recipient,
            recipientId: $recipientId,
            payload: $payload,
            sendAtUtc: $sendAtUtc,
        );

        $this->reminders->save($reminder);

        return $reminder;
    }

    private function isUsable(?string $value): bool
    {
        return null !== $value && '' !== trim($value);
    }
}
