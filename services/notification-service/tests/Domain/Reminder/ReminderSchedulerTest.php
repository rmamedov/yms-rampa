<?php

declare(strict_types=1);

namespace App\Tests\Domain\Reminder;

use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderPlan;
use App\Domain\Reminder\ReminderScheduler;
use App\Domain\Reminder\ReminderStatus;
use App\Tests\Support\NotificationTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Планувальник нагадувань NOT-T3 і NOT-T4 (NOT-06).
 */
#[CoversClass(ReminderScheduler::class)]
final class ReminderSchedulerTest extends TestCase
{
    private NotificationTestEnvironment $env;

    protected function setUp(): void
    {
        // «Зараз» — 04.09.2026 09:00 UTC (12:00 за Києвом).
        $this->env = new NotificationTestEnvironment();
    }

    public function testBothRemindersAreScheduledForDistantSlot(): void
    {
        $created = $this->env->scheduler->scheduleForBooking($this->plan());

        self::assertCount(3, $created, 'NOT-T3 водію і постачальнику + NOT-T4 водію.');

        $templates = array_map(static fn ($r): string => $r->template()->code(), $created);
        self::assertSame(['NOT-T3', 'NOT-T3', 'NOT-T4'], $templates);

        self::assertSame('2026-09-07 11:30:00', $created[0]->sendAtUtc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-08 09:30:00', $created[2]->sendAtUtc()->format('Y-m-d H:i:s'));
    }

    public function testReminderPayloadUsesKyivLocalTime(): void
    {
        $created = $this->env->scheduler->scheduleForBooking($this->plan());

        self::assertSame('08.09.2026', $created[0]->payload()['date']);
        self::assertSame('14:30', $created[0]->payload()['time']);
        self::assertSame('1998', $created[0]->payload()['externalId']);
    }

    public function testTwentyFourHourReminderGoesToDriverBySmsAndSupplierByEmail(): void
    {
        $created = $this->env->scheduler->scheduleForBooking($this->plan());

        self::assertSame(NotificationChannel::Sms, $created[0]->channel());
        self::assertSame('+380671234567', $created[0]->recipient());
        self::assertSame(NotificationChannel::Email, $created[1]->channel());
        self::assertSame('supplier@example.com', $created[1]->recipient());
        self::assertSame(NotificationTemplate::Reminder2h, $created[2]->template());
    }

    /**
     * NOT-06: якщо бронювання створено менш ніж за 24 год до слота,
     * 24-годинне нагадування не ставиться зовсім.
     */
    public function testTwentyFourHourReminderIsSkippedForNearSlot(): void
    {
        $created = $this->env->scheduler->scheduleForBooking($this->plan(
            NotificationTestEnvironment::utc('2026-09-04 19:00:00'),
        ));

        self::assertCount(1, $created);
        self::assertSame(NotificationTemplate::Reminder2h, $created[0]->template());
    }

    public function testNoRemindersForSlotWithinTwoHours(): void
    {
        $created = $this->env->scheduler->scheduleForBooking($this->plan(
            NotificationTestEnvironment::utc('2026-09-04 10:00:00'),
        ));

        self::assertSame([], $created);
    }

    public function testReminderWithoutDriverPhoneOnlyNotifiesSupplier(): void
    {
        $created = $this->env->scheduler->scheduleForBooking(new ReminderPlan(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            address: 'вул. Хрещатик, 1',
            rampNumber: '3',
            driverPhone: null,
            supplierEmail: 'supplier@example.com',
            supplierId: 'sup-1',
        ));

        self::assertCount(1, $created);
        self::assertSame(NotificationChannel::Email, $created[0]->channel());
    }

    /**
     * NOT-06: скасування бронювання знімає заплановані нагадування.
     */
    public function testCancellationRemovesScheduledReminders(): void
    {
        $this->env->scheduler->scheduleForBooking($this->plan());

        self::assertSame(3, $this->env->scheduler->cancelForBooking('bkg-1'));

        foreach ($this->env->reminders->all() as $reminder) {
            self::assertSame(ReminderStatus::Cancelled, $reminder->status());
        }

        $this->env->clock->set(NotificationTestEnvironment::utc('2026-09-08 12:00:00'));
        self::assertSame(0, $this->env->scheduler->dispatchDue());
        self::assertSame(0, $this->env->sms->sentCount());
    }

    public function testCancellingTwiceDoesNotDoubleCount(): void
    {
        $this->env->scheduler->scheduleForBooking($this->plan());

        self::assertSame(3, $this->env->scheduler->cancelForBooking('bkg-1'));
        self::assertSame(0, $this->env->scheduler->cancelForBooking('bkg-1'));
    }

    public function testDueRemindersAreDispatched(): void
    {
        $this->env->scheduler->scheduleForBooking($this->plan());

        $this->env->clock->set(NotificationTestEnvironment::utc('2026-09-07 11:30:00'));
        self::assertSame(2, $this->env->scheduler->dispatchDue());

        self::assertSame(1, $this->env->sms->sentCount());
        self::assertSame(1, $this->env->email->sentCount());
        self::assertStringContainsString(
            'Нагадування: завтра 08.09.2026 о 14:30',
            (string) $this->env->sms->lastMessage()?->text,
        );

        // Повторний прогін уже нічого не відправляє.
        self::assertSame(0, $this->env->scheduler->dispatchDue());
    }

    public function testTwoHourReminderTextMentionsArrivalButton(): void
    {
        $this->env->scheduler->scheduleForBooking($this->plan());

        $this->env->clock->set(NotificationTestEnvironment::utc('2026-09-08 09:30:00'));
        $this->env->scheduler->dispatchDue();

        $texts = array_map(static fn ($m): string => $m->text, $this->env->sms->sentMessages());
        $lastText = $texts[array_key_last($texts)];

        self::assertStringContainsString('Через 2 години о 14:30', $lastText);
        self::assertStringContainsString('Не забудьте натиснути На місці після прибуття.', $lastText);
    }

    /**
     * NOT-05: нагадування некритичні, тому opt-out їх глушить.
     */
    public function testOptedOutDriverDoesNotReceiveReminder(): void
    {
        $this->env->optOut->optOutAll('drv-1');
        $this->env->scheduler->scheduleForBooking($this->plan());

        $this->env->clock->set(NotificationTestEnvironment::utc('2026-09-07 11:30:00'));
        $this->env->scheduler->dispatchDue();

        self::assertSame(0, $this->env->sms->sentCount());
        self::assertSame(1, $this->env->email->sentCount());
    }

    private function plan(?\DateTimeImmutable $slotStart = null): ReminderPlan
    {
        return new ReminderPlan(
            bookingId: 'bkg-1',
            slotStartUtc: $slotStart ?? NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            address: 'вул. Хрещатик, 1',
            rampNumber: '3',
            driverPhone: '+380671234567',
            driverId: 'drv-1',
            supplierEmail: 'supplier@example.com',
            supplierId: 'sup-1',
        );
    }
}
