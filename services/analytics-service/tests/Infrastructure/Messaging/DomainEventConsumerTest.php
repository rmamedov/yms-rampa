<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging;

use App\Domain\Booking\BookingStatus;
use App\Domain\Exception\MalformedEventException;
use App\Domain\Projection\EventProjector;
use App\Domain\Projection\ProjectionOutcome;
use App\Infrastructure\InMemory\InMemoryBookingFactRepository;
use App\Infrastructure\Messaging\DomainEventConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Вхід потоку подій RabbitMQ у read-моделі: доставка at-least-once
 * не повинна псувати факт.
 */
#[CoversClass(DomainEventConsumer::class)]
final class DomainEventConsumerTest extends TestCase
{
    private InMemoryBookingFactRepository $repository;
    private DomainEventConsumer $consumer;

    protected function setUp(): void
    {
        $this->repository = new InMemoryBookingFactRepository();
        $this->consumer = new DomainEventConsumer(new EventProjector($this->repository));
    }

    #[Test]
    public function buildsFactFromJsonStreamAndIgnoresRepeatedMessages(): void
    {
        $stream = [
            $this->message('evt-1', 'BookingCreated', [
                'bookingId' => 'b1',
                'storeId' => 'store-1',
                'city' => 'Київ',
                'supplierId' => 'sup-1',
                'rampId' => 'ramp-1',
                'slotStart' => '2026-03-16T08:00:00+00:00',
                'slotEnd' => '2026-03-16T08:30:00+00:00',
                'type' => 'scheduled',
                'palletsCount' => 10,
            ]),
            $this->message('evt-2', 'BookingArrived', ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T07:58:00+00:00']),
            // дубль тієї самої події (повторна доставка брокером)
            $this->message('evt-2', 'BookingArrived', ['bookingId' => 'b1', 'arrivedAt' => '2026-03-16T07:58:00+00:00']),
            '',
        ];

        $results = $this->consumer->consumeStream($stream);

        self::assertSame(
            [ProjectionOutcome::Applied, ProjectionOutcome::Applied, ProjectionOutcome::Duplicate],
            array_map(static fn ($r): ProjectionOutcome => $r->outcome, $results),
        );
        self::assertSame(1, $this->repository->countAll());
        self::assertSame(BookingStatus::Arrived, $this->repository->findByBookingId('b1')?->status());
    }

    #[Test]
    public function brokenJsonIsRejectedWithDomainError(): void
    {
        $this->expectException(MalformedEventException::class);

        $this->consumer->consumeJson('{не json');
    }

    #[Test]
    public function messageWithoutEventIdIsRejected(): void
    {
        $this->expectException(MalformedEventException::class);
        $this->expectExceptionMessage('eventId');

        $this->consumer->consumeJson((string) json_encode([
            'name' => 'BookingArrived',
            'occurredAt' => '2026-03-16T08:00:00+00:00',
            'payload' => ['bookingId' => 'b1'],
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function message(string $eventId, string $name, array $payload): string
    {
        return (string) json_encode([
            'eventId' => $eventId,
            'name' => $name,
            'occurredAt' => '2026-03-16T08:00:00+00:00',
            'payload' => $payload,
        ], \JSON_THROW_ON_ERROR);
    }
}
