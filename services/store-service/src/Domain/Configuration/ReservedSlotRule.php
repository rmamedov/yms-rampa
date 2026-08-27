<?php

declare(strict_types=1);

namespace App\Domain\Configuration;

use App\Domain\Shared\ValidationException;

/**
 * Правило резервування слота під конкретного постачальника (STC-40..STC-43, 10.2.3).
 *
 * DATA-33: рівно одне з полів dayOfWeek / date має бути заповнене (XOR).
 * rampId обовʼязковий — бронювання завжди привʼязане до конкретної рампи.
 */
final readonly class ReservedSlotRule
{
    public const int SCHEMA_VERSION = 1;

    public int $slotStartMinutes;

    public function __construct(
        public string $id,
        public string $storeId,
        public string $supplierId,
        public string $rampId,
        public string $slotStartTime,
        public ?int $dayOfWeek,
        public ?string $date,
        public \DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo = null,
        public bool $active = true,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
        if ('' === trim($storeId)) {
            throw ValidationException::config('Не вказано магазин правила резерву', ['storeId' => 'Обовʼязкове поле']);
        }

        if ('' === trim($supplierId)) {
            throw ValidationException::config(
                'Постачальник для правила резерву обовʼязковий',
                ['supplierId' => 'Оберіть постачальника'],
            );
        }

        if ('' === trim($rampId)) {
            throw ValidationException::config(
                'Рампа для правила резерву обовʼязкова — бронювання завжди привʼязане до рампи',
                ['rampId' => 'Оберіть рампу'],
            );
        }

        $hasDayOfWeek = null !== $dayOfWeek;
        $hasDate = null !== $date;

        if ($hasDayOfWeek === $hasDate) {
            throw ValidationException::config(
                'Правило резерву має містити рівно одне з полів: день тижня або конкретна дата',
                ['dayOfWeek' => 'Заповніть або день тижня, або дату — але не обидва'],
            );
        }

        if ($hasDayOfWeek && ($dayOfWeek < 1 || $dayOfWeek > 7)) {
            throw ValidationException::config('День тижня має бути в межах 1–7', ['dayOfWeek' => 'Допустимі значення 1–7']);
        }

        if ($hasDate) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);

            if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
                throw ValidationException::config(
                    \sprintf('Дата «%s» має бути у форматі YYYY-MM-DD', (string) $date),
                    ['date' => 'Очікується формат YYYY-MM-DD'],
                );
            }
        }

        if (null !== $validTo && $validTo <= $validFrom) {
            throw ValidationException::config(
                'Кінець періоду дії має бути пізнішим за початок',
                ['validTo' => 'Кінець періоду має бути пізнішим за початок'],
            );
        }

        $this->slotStartMinutes = TimeInterval::parse($slotStartTime, 'slotStartTime');
    }

    /** Ефективний день тижня правила (для разових резервів — обчислюється з дати). */
    public function effectiveDayOfWeek(): int
    {
        if (null !== $this->dayOfWeek) {
            return $this->dayOfWeek;
        }

        return (int) (new \DateTimeImmutable((string) $this->date))->format('N');
    }

    public function isWeekly(): bool
    {
        return null !== $this->dayOfWeek;
    }

    public function isValidAt(\DateTimeImmutable $moment): bool
    {
        if (!$this->active) {
            return false;
        }

        if ($moment < $this->validFrom) {
            return false;
        }

        return null === $this->validTo || $moment <= $this->validTo;
    }

    /**
     * STC-42: перетин двох правил резерву на один слот заборонений.
     * Правила конфліктують, якщо збігаються магазин, рампа і час початку слота,
     * припадають на той самий день і мають спільний період дії.
     */
    public function conflictsWith(self $other): bool
    {
        if ($other->id === $this->id) {
            return false;
        }

        if (!$this->active || !$other->active) {
            return false;
        }

        if ($this->storeId !== $other->storeId || $this->rampId !== $other->rampId) {
            return false;
        }

        if ($this->slotStartMinutes !== $other->slotStartMinutes) {
            return false;
        }

        if (!$this->sameOccurrence($other)) {
            return false;
        }

        return $this->validityOverlaps($other);
    }

    private function sameOccurrence(self $other): bool
    {
        // Разові резерви конфліктують лише на однакову дату.
        if (!$this->isWeekly() && !$other->isWeekly()) {
            return $this->date === $other->date;
        }

        // Щотижневе правило перекриває разовий резерв того самого дня тижня і навпаки.
        return $this->effectiveDayOfWeek() === $other->effectiveDayOfWeek();
    }

    private function validityOverlaps(self $other): bool
    {
        $thisTo = $this->validTo;
        $otherTo = $other->validTo;

        if (null !== $thisTo && $thisTo < $other->validFrom) {
            return false;
        }

        return null === $otherTo || $otherTo >= $this->validFrom;
    }
}
