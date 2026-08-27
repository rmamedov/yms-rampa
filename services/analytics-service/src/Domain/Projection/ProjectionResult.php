<?php

declare(strict_types=1);

namespace App\Domain\Projection;

final readonly class ProjectionResult
{
    public function __construct(
        public ProjectionOutcome $outcome,
        public ?string $bookingId = null,
        public ?string $reason = null,
    ) {
    }

    public static function applied(string $bookingId): self
    {
        return new self(ProjectionOutcome::Applied, $bookingId);
    }

    public static function duplicate(string $bookingId): self
    {
        return new self(ProjectionOutcome::Duplicate, $bookingId, 'Подію вже було застосовано раніше.');
    }

    public static function ignored(string $reason): self
    {
        return new self(ProjectionOutcome::Ignored, null, $reason);
    }

    public static function orphan(string $bookingId): self
    {
        return new self(
            ProjectionOutcome::Orphan,
            $bookingId,
            'Подію отримано раніше за BookingCreated — факт ще не створено.',
        );
    }

    public function isApplied(): bool
    {
        return $this->outcome === ProjectionOutcome::Applied;
    }
}
