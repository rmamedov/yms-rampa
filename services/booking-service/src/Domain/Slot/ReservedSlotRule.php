<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use InvalidArgumentException;

/**
 * Правило резервування слота за конкретним критичним постачальником.
 *
 * Задається або щотижневим днем (dayOfWeek), або разовою датою (date) —
 * рівно одним із двох. Рампа обовʼязкова: бронювання завжди привʼязане до рампи.
 */
final readonly class ReservedSlotRule
{
    public function __construct(
        public string $supplierId,
        public string $rampId,
        public string $slotStartTime,
        public ?int $dayOfWeek = null,
        public ?string $date = null,
        public ?string $validFrom = null,
        public ?string $validTo = null,
        public bool $active = true,
    ) {
        if ('' === $supplierId || '' === $rampId) {
            throw new InvalidArgumentException('supplierId та rampId обовʼязкові для правила резерву');
        }

        if ((null === $dayOfWeek) === (null === $date)) {
            throw new InvalidArgumentException(
                'Правило резерву задається або dayOfWeek (щотижнево), або date (разово) — рівно одним із двох'
            );
        }

        if (null !== $dayOfWeek && ($dayOfWeek < 1 || $dayOfWeek > 7)) {
            throw new InvalidArgumentException(\sprintf('dayOfWeek має бути 1..7, отримано %d', $dayOfWeek));
        }

        if (1 !== preg_match('/^\d{2}:\d{2}$/', $slotStartTime)) {
            throw new InvalidArgumentException('slotStartTime має бути у форматі HH:MM');
        }
    }

    /**
     * Чи діє правило для слота, що починається о $localTime локальної дати $date.
     */
    public function matches(string $date, int $dayOfWeek, string $localTime, string $rampId): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($this->rampId !== $rampId || $this->slotStartTime !== $localTime) {
            return false;
        }

        if (null !== $this->validFrom && $date < $this->validFrom) {
            return false;
        }

        if (null !== $this->validTo && $date > $this->validTo) {
            return false;
        }

        return null !== $this->date ? $this->date === $date : $this->dayOfWeek === $dayOfWeek;
    }
}
