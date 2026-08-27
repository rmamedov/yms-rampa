<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Domain\Event\BookingCancelled;
use App\Domain\Event\BookingCreated;
use App\Domain\Event\BookingDelaySet;
use App\Domain\Event\BookingReassigned;
use App\Domain\Event\BookingRejected;
use App\Domain\Event\BookingType;
use App\Domain\Event\ReassignmentInitiator;
use App\Domain\Event\SlotReleased;
use App\Domain\Notification\NotificationTemplate;
use App\Domain\Reminder\ReminderStatus;
use App\MessageHandler\BookingCancelledHandler;
use App\MessageHandler\BookingCreatedHandler;
use App\MessageHandler\BookingDelaySetHandler;
use App\MessageHandler\BookingReassignedHandler;
use App\MessageHandler\BookingRejectedHandler;
use App\MessageHandler\SlotReleasedHandler;
use App\Tests\Support\NotificationTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Обробники канонічних доменних подій (NOT-02, NOT-16, NOT-17, NOT-18).
 */
#[CoversClass(BookingCreatedHandler::class)]
#[CoversClass(BookingCancelledHandler::class)]
#[CoversClass(BookingDelaySetHandler::class)]
#[CoversClass(BookingRejectedHandler::class)]
#[CoversClass(BookingReassignedHandler::class)]
#[CoversClass(SlotReleasedHandler::class)]
final class BookingEventHandlersTest extends TestCase
{
    private const string PORTAL = 'https://yms.silpo.ua/b';

    private NotificationTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new NotificationTestEnvironment();
    }

    // --- NOT-T2: підтвердження бронювання ---

    public function testBookingCreatedSendsConfirmationToSupplierAndDriver(): void
    {
        ($this->createdHandler())($this->bookingCreated());

        self::assertSame(3, $this->env->repository->count(), 'E-mail постачальнику, SMS постачальнику, SMS водію.');
        self::assertSame(2, $this->env->sms->sentCount());
        self::assertSame(1, $this->env->email->sentCount());

        foreach ($this->env->repository->all() as $notification) {
            self::assertSame(NotificationTemplate::BookingConfirmed, $notification->template());
            self::assertSame('bkg-1', $notification->correlationId());
        }
    }

    public function testBookingCreatedSchedulesReminders(): void
    {
        ($this->createdHandler())($this->bookingCreated());

        self::assertCount(3, $this->env->reminders->all());
    }

    public function testWalkInBookingWithoutDriverOnlyNotifiesSupplier(): void
    {
        ($this->createdHandler())(new BookingCreated(
            bookingId: 'bkg-2',
            type: BookingType::WalkIn,
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-04 16:00'),
            storeExternalId: '1998',
            city: 'Київ',
            address: 'вул. Хрещатик, 1',
            rampNumber: '3',
            vehicleNumber: 'AA1234BB',
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            portalUrl: self::PORTAL,
        ));

        self::assertSame(1, $this->env->repository->count());
        self::assertSame(1, $this->env->email->sentCount());
        self::assertSame(0, $this->env->sms->sentCount());
    }

    // --- NOT-16: перенесення слота ---

    public function testRescheduleSendsSingleT7AndNoT2(): void
    {
        ($this->createdHandler())($this->bookingCreated(bookingId: 'bkg-2', rescheduleOf: 'bkg-1'));

        foreach ($this->env->repository->all() as $notification) {
            self::assertSame(NotificationTemplate::BookingRescheduled, $notification->template());
        }
        self::assertStringContainsString(
            'Ваше бронювання перенесено: нова дата',
            (string) $this->env->sms->lastMessage()?->text,
        );
    }

    public function testCancellationLinkedToRescheduleSendsNoT5(): void
    {
        ($this->createdHandler())($this->bookingCreated(bookingId: 'bkg-2', rescheduleOf: 'bkg-1'));
        $before = $this->env->repository->count();

        ($this->cancelledHandler())($this->bookingCancelled());

        self::assertSame($before, $this->env->repository->count(), 'Окреме NOT-T5 не надсилається (NOT-16).');
    }

    public function testCancellationArrivingFirstIsAlsoRecognisedAsReschedule(): void
    {
        ($this->cancelledHandler())($this->bookingCancelled(rescheduledTo: 'bkg-2'));

        self::assertSame(0, $this->env->repository->count());
        self::assertTrue($this->env->reschedules->isRescheduled('bkg-1'));
        self::assertSame('bkg-2', $this->env->reschedules->newBookingFor('bkg-1'));
    }

    public function testRescheduleMovesRemindersToTheNewBooking(): void
    {
        ($this->createdHandler())($this->bookingCreated());
        self::assertCount(3, $this->env->reminders->all());

        ($this->createdHandler())($this->bookingCreated(
            bookingId: 'bkg-2',
            rescheduleOf: 'bkg-1',
            slotStart: NotificationTestEnvironment::kyiv('2026-09-09 10:00'),
        ));

        $byBooking = [];
        foreach ($this->env->reminders->all() as $reminder) {
            $byBooking[$reminder->bookingId()][] = $reminder->status();
        }

        self::assertSame(
            [ReminderStatus::Cancelled, ReminderStatus::Cancelled, ReminderStatus::Cancelled],
            $byBooking['bkg-1'],
        );
        self::assertCount(3, $byBooking['bkg-2']);
    }

    // --- NOT-T5: скасування ---

    public function testCancellationSendsT5ToSupplierAndDriver(): void
    {
        ($this->cancelledHandler())($this->bookingCancelled());

        self::assertSame(2, $this->env->repository->count());
        self::assertSame(1, $this->env->email->sentCount());
        self::assertSame(1, $this->env->sms->sentCount());
        self::assertStringContainsString(
            'скасовано. Причина: Ремонт рампи.',
            (string) $this->env->sms->lastMessage()?->text,
        );
    }

    /**
     * Критерій приймання 11.2: скасування знімає заплановане нагадування.
     */
    public function testCancellationCancelsPendingReminders(): void
    {
        ($this->createdHandler())($this->bookingCreated());
        ($this->cancelledHandler())($this->bookingCancelled());

        foreach ($this->env->reminders->all() as $reminder) {
            self::assertSame(ReminderStatus::Cancelled, $reminder->status());
        }
    }

    // --- NOT-T6: затримка ---

    public function testDelaySendsSmsToSupplier(): void
    {
        (new BookingDelaySetHandler($this->env->dispatcher))(new BookingDelaySet(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            reason: 'Затримка транспорту',
            supplierId: 'sup-1',
            supplierPhone: '+380501112233',
        ));

        self::assertSame(1, $this->env->sms->sentCount());
        self::assertStringContainsString('зафіксована затримка', (string) $this->env->sms->lastMessage()?->text);
    }

    /**
     * NOT-05: затримка не входить до критичних сповіщень, тому opt-out діє.
     */
    public function testDelayIsSuppressedByOptOut(): void
    {
        $this->env->optOut->optOutAll('sup-1');

        (new BookingDelaySetHandler($this->env->dispatcher))(new BookingDelaySet(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            reason: 'Затримка транспорту',
            supplierId: 'sup-1',
            supplierPhone: '+380501112233',
        ));

        self::assertSame(0, $this->env->repository->count());
    }

    // --- NOT-17: відмова в прийомі ---

    public function testRejectionSendsEmailToSupplierOnly(): void
    {
        ($this->rejectedHandler())(new BookingRejected(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            vehicleNumber: 'AA1234BB',
            reason: 'Невідповідність документів',
            comment: 'Немає ТТН',
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            portalUrl: self::PORTAL,
        ));

        self::assertSame(1, $this->env->repository->count());
        self::assertSame(1, $this->env->email->sentCount());
        self::assertSame(0, $this->env->sms->sentCount(), 'SMS водію про відмову — фаза 2 (NOT-17).');

        $message = $this->env->email->lastMessage();
        self::assertNotNull($message);
        self::assertStringContainsString('Відмовлено в прийомі', $message->text);
        self::assertStringContainsString('Причина: Невідповідність документів, Немає ТТН.', $message->text);
    }

    /**
     * NOT-05: відмова — критичне сповіщення, opt-out не діє.
     */
    public function testRejectionIgnoresOptOut(): void
    {
        $this->env->optOut->optOutAll('sup-1');

        ($this->rejectedHandler())(new BookingRejected(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            vehicleNumber: 'AA1234BB',
            reason: 'Невідповідність документів',
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            portalUrl: self::PORTAL,
        ));

        self::assertSame(1, $this->env->email->sentCount());
    }

    // --- NOT-18: перепризначення ---

    public function testStoreInitiatedReassignmentNotifiesDriverAndSupplier(): void
    {
        ($this->reassignedHandler())($this->reassigned(ReassignmentInitiator::Store, rampNumber: '5'));

        self::assertSame(1, $this->env->sms->sentCount());
        self::assertSame(1, $this->env->email->sentCount());
        self::assertStringContainsString('філія №1998: рампа 5.', (string) $this->env->sms->lastMessage()?->text);
    }

    public function testSupplierInitiatedReassignmentDoesNotEmailInitiator(): void
    {
        ($this->reassignedHandler())($this->reassigned(
            ReassignmentInitiator::Supplier,
            vehicleNumber: 'BB5678CC',
            driverChanged: true,
        ));

        self::assertSame(1, $this->env->sms->sentCount());
        self::assertSame(0, $this->env->email->sentCount());
        self::assertStringContainsString('авто BB5678CC / водій.', (string) $this->env->sms->lastMessage()?->text);
    }

    public function testReassignmentWithoutChangesSendsNothing(): void
    {
        ($this->reassignedHandler())($this->reassigned(ReassignmentInitiator::Store));

        self::assertSame(0, $this->env->repository->count());
    }

    // --- SlotReleased ---

    public function testSlotReleasedCancelsReminders(): void
    {
        ($this->createdHandler())($this->bookingCreated());

        (new SlotReleasedHandler($this->env->scheduler))(new SlotReleased(
            slotId: 'slot-1',
            storeId: 'store-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            bookingId: 'bkg-1',
        ));

        foreach ($this->env->reminders->all() as $reminder) {
            self::assertSame(ReminderStatus::Cancelled, $reminder->status());
        }
    }

    public function testSlotReleasedWithoutBookingIsIgnored(): void
    {
        ($this->createdHandler())($this->bookingCreated());

        (new SlotReleasedHandler($this->env->scheduler))(new SlotReleased(
            slotId: 'slot-1',
            storeId: 'store-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
        ));

        foreach ($this->env->reminders->all() as $reminder) {
            self::assertSame(ReminderStatus::Scheduled, $reminder->status());
        }
    }

    // --- фабрики ---

    private function createdHandler(): BookingCreatedHandler
    {
        return new BookingCreatedHandler(
            $this->env->dispatcher,
            $this->env->scheduler,
            $this->env->reschedules,
            self::PORTAL,
        );
    }

    private function cancelledHandler(): BookingCancelledHandler
    {
        return new BookingCancelledHandler(
            $this->env->dispatcher,
            $this->env->scheduler,
            $this->env->reschedules,
            self::PORTAL,
        );
    }

    private function rejectedHandler(): BookingRejectedHandler
    {
        return new BookingRejectedHandler($this->env->dispatcher, $this->env->scheduler, self::PORTAL);
    }

    private function reassignedHandler(): BookingReassignedHandler
    {
        return new BookingReassignedHandler($this->env->dispatcher, self::PORTAL);
    }

    private function bookingCreated(
        string $bookingId = 'bkg-1',
        ?string $rescheduleOf = null,
        ?\DateTimeImmutable $slotStart = null,
    ): BookingCreated {
        return new BookingCreated(
            bookingId: $bookingId,
            type: BookingType::Scheduled,
            slotStartUtc: $slotStart ?? NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            city: 'Київ',
            address: 'вул. Хрещатик, 1',
            rampNumber: '3',
            vehicleNumber: 'AA1234BB',
            orderId: '12345',
            rescheduleOf: $rescheduleOf,
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            supplierPhone: '+380501112233',
            driverId: 'drv-1',
            driverPhone: '+380671234567',
            portalUrl: self::PORTAL,
        );
    }

    private function bookingCancelled(?string $rescheduledTo = null): BookingCancelled
    {
        return new BookingCancelled(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            reason: 'Ремонт рампи',
            rescheduledToBookingId: $rescheduledTo,
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            driverId: 'drv-1',
            driverPhone: '+380671234567',
            portalUrl: self::PORTAL,
        );
    }

    private function reassigned(
        ReassignmentInitiator $initiator,
        ?string $rampNumber = null,
        ?string $vehicleNumber = null,
        bool $driverChanged = false,
    ): BookingReassigned {
        return new BookingReassigned(
            bookingId: 'bkg-1',
            slotStartUtc: NotificationTestEnvironment::kyiv('2026-09-08 14:30'),
            storeExternalId: '1998',
            initiator: $initiator,
            newRampNumber: $rampNumber,
            newVehicleNumber: $vehicleNumber,
            driverChanged: $driverChanged,
            supplierId: 'sup-1',
            supplierEmail: 'supplier@example.com',
            driverId: 'drv-1',
            driverPhone: '+380671234567',
            portalUrl: self::PORTAL,
        );
    }
}
