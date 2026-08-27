<?php

declare(strict_types=1);

namespace App\Domain\Projection;

use App\Domain\Booking\BookingType;
use App\Domain\Booking\RejectionReason;
use App\Domain\Exception\MalformedEventException;
use App\Domain\Fact\BookingFact;
use App\Domain\Fact\BookingFactRepository;

/**
 * Проєктор доменних подій у read-модель BookingFact (KPI-05: джерело даних —
 * доменні події з RabbitMQ, а не прямі запити в БД booking-service).
 *
 * Гарантії:
 *  1. Ідемпотентність. Брокер доставляє події at-least-once. Кожен застосований
 *     eventId зберігається у факті; повторна доставка тієї самої події
 *     повертає ProjectionOutcome::Duplicate і не змінює жодного поля.
 *  2. Стійкість до порушення порядку. Статус рухається лише вперед за рангом
 *     (booked → arrived → unloading → completed | cancelled | no_show | rejected),
 *     а мітки часу записуються лише якщо ще порожні. Тому подія, що прийшла
 *     із запізненням, не «відкочує» факт назад.
 *  3. Події, що не стосуються бронювань (BranchSynced, DriverCreated тощо),
 *     ігноруються без помилки.
 */
final readonly class EventProjector
{
    public function __construct(private BookingFactRepository $repository)
    {
    }

    public function project(DomainEvent $event): ProjectionResult
    {
        if (!$event->name->affectsBookingFact()) {
            return ProjectionResult::ignored(sprintf(
                'Подія %s не впливає на read-модель бронювань.',
                $event->name->value,
            ));
        }

        $bookingId = $event->requiredString('bookingId');
        $fact = $this->repository->findByBookingId($bookingId);

        if ($fact !== null && $fact->hasProcessed($event->eventId)) {
            return ProjectionResult::duplicate($bookingId);
        }

        if ($event->name === DomainEventName::BookingCreated) {
            $fact ??= $this->createFact($event, $bookingId);
        }

        if ($fact === null) {
            // Подія прийшла раніше за BookingCreated: факт створити нізвідки,
            // повідомляємо про сироту — повідомлення повертається в чергу/DLQ.
            return ProjectionResult::orphan($bookingId);
        }

        $this->apply($event, $fact);
        $fact->markProcessed($event->eventId, $event->occurredAt);
        $this->repository->save($fact);

        return ProjectionResult::applied($bookingId);
    }

    /**
     * @param iterable<DomainEvent> $events
     *
     * @return list<ProjectionResult>
     */
    public function projectMany(iterable $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results[] = $this->project($event);
        }

        return $results;
    }

    private function createFact(DomainEvent $event, string $bookingId): BookingFact
    {
        $typeValue = $event->requiredString('type');
        $type = BookingType::tryFrom($typeValue);
        if ($type === null) {
            throw new MalformedEventException(sprintf(
                'BookingCreated: невідомий тип бронювання «%s» (очікується scheduled або walk_in).',
                $typeValue,
            ));
        }

        return new BookingFact(
            bookingId: $bookingId,
            storeId: $event->requiredString('storeId'),
            city: $event->requiredString('city'),
            supplierId: $event->requiredString('supplierId'),
            rampId: $event->requiredString('rampId'),
            slotStart: $event->requiredDate('slotStart'),
            slotEnd: $event->requiredDate('slotEnd'),
            type: $type,
            palletsCount: $event->requiredInt('palletsCount'),
            rescheduleOf: $event->optionalString('rescheduleOf'),
            createdAt: $event->occurredAt,
        );
    }

    /**
     * Walk-in (глосарій 1.5): позапланове прибуття реєструє магазин, і
     * бронювання створюється одразу в статусі arrived. Тому подія BookingCreated
     * з type=walk_in одразу фіксує факт прибуття — інакше така машина не
     * потрапила б у знаменник KPI-02 і в KPI-03.
     */
    private function applyWalkInArrival(DomainEvent $event, BookingFact $fact): void
    {
        if ($fact->type !== BookingType::WalkIn) {
            return;
        }

        $fact->applyArrived($event->dateOr('arrivedAt', $event->occurredAt));
    }

    private function apply(DomainEvent $event, BookingFact $fact): void
    {
        match ($event->name) {
            DomainEventName::BookingCreated => $this->applyWalkInArrival($event, $fact),
            DomainEventName::BookingArrived => $fact->applyArrived(
                $event->dateOr('arrivedAt', $event->occurredAt),
            ),
            DomainEventName::UnloadingStarted => $fact->applyUnloadingStarted(
                $event->dateOr('startedAt', $event->occurredAt),
            ),
            DomainEventName::UnloadingCompleted => $fact->applyUnloadingCompleted(
                $event->dateOr('completedAt', $event->occurredAt),
                $event->optionalInt('unloadedPalletsCount'),
                $event->bool('partialUnload'),
            ),
            DomainEventName::BookingCancelled => $fact->applyCancelled(
                $event->dateOr('cancelledAt', $event->occurredAt),
            ),
            DomainEventName::BookingNoShow => $fact->applyNoShow(
                $event->dateOr('noShowAt', $event->occurredAt),
            ),
            DomainEventName::BookingRejected => $fact->applyRejected(
                $event->dateOr('rejectedAt', $event->occurredAt),
                RejectionReason::fromCode($event->optionalString('reason') ?? $event->optionalString('rejectedReason')),
            ),
            DomainEventName::BookingDelaySet => $fact->applyDelay(
                $event->bool('delayed', true),
                $event->optionalString('reason') ?? $event->optionalString('delayReason'),
                $event->optionalDate('eta') ?? $event->optionalDate('delayEta'),
            ),
            DomainEventName::BookingReassigned => $fact->applyReassignment(
                $event->requiredString('rampId'),
            ),
            default => null,
        };
    }
}
