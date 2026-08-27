<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Dispatch\ExponentialBackoffRetryPolicy;
use App\Domain\Dispatch\NotificationDispatcher;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\TemplateRenderer;
use App\Domain\Reminder\ReminderScheduler;
use App\Domain\Security\SecretMasker;
use App\Infrastructure\InMemory\FrozenClock;
use App\Infrastructure\InMemory\InMemoryLogger;
use App\Infrastructure\InMemory\InMemoryNotificationRepository;
use App\Infrastructure\InMemory\InMemoryOptOutRegistry;
use App\Infrastructure\InMemory\InMemoryRescheduleRegistry;
use App\Infrastructure\InMemory\InMemoryScheduledReminderRepository;
use App\Infrastructure\InMemory\InMemoryTransport;
use App\Infrastructure\Transport\ChannelTransportRegistry;
use App\Infrastructure\Transport\ViberTransport;

/**
 * Складання доменного «стенду» для тестів на InMemory-реалізаціях.
 *
 * Жодного MongoDB, Redis чи мережі — усе працює в памʼяті.
 */
final class NotificationTestEnvironment
{
    public InMemoryNotificationRepository $repository;
    public InMemoryScheduledReminderRepository $reminders;
    public InMemoryOptOutRegistry $optOut;
    public InMemoryRescheduleRegistry $reschedules;
    public InMemoryTransport $sms;
    public InMemoryTransport $email;
    public InMemoryLogger $logger;
    public FrozenClock $clock;
    public ExponentialBackoffRetryPolicy $retryPolicy;
    public NotificationDispatcher $dispatcher;
    public ReminderScheduler $scheduler;

    public function __construct(
        int $maxAttempts = 3,
        string $now = '2026-09-04 09:00:00',
    ) {
        $this->repository = new InMemoryNotificationRepository();
        $this->reminders = new InMemoryScheduledReminderRepository();
        $this->optOut = new InMemoryOptOutRegistry();
        $this->reschedules = new InMemoryRescheduleRegistry();
        $this->sms = new InMemoryTransport([NotificationChannel::Sms], 'sms-test');
        $this->email = new InMemoryTransport([NotificationChannel::Email], 'email-test');
        $this->logger = new InMemoryLogger();
        $this->clock = new FrozenClock($now);
        $this->retryPolicy = new ExponentialBackoffRetryPolicy(maxAttempts: $maxAttempts);

        $this->dispatcher = new NotificationDispatcher(
            repository: $this->repository,
            transports: new ChannelTransportRegistry([$this->sms, $this->email, new ViberTransport()]),
            renderer: new TemplateRenderer(),
            retryPolicy: $this->retryPolicy,
            clock: $this->clock,
            masker: new SecretMasker(),
            optOut: $this->optOut,
            logger: $this->logger,
        );

        $this->scheduler = new ReminderScheduler(
            reminders: $this->reminders,
            dispatcher: $this->dispatcher,
            clock: $this->clock,
        );
    }

    public static function utc(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime, new \DateTimeZone('UTC'));
    }

    /** Київський час у вигляді моменту UTC — так, як приходить slotStart. */
    public static function kyiv(string $dateTime): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($dateTime, new \DateTimeZone('Europe/Kyiv')))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
