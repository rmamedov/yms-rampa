<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Обчислення сітки слотів магазину на дату (GRID-01).
 *
 * Порядок кроків відповідає специфікації: нарізання вікон прийому на слоти
 * окремо для кожної рампи → блокування → резерви → бронювання → холди →
 * минуле і lead time → горизонт бронювання.
 *
 * Алгоритм детермінований (SLOT-04в): однакові вхідні дані завжди дають
 * однакову сітку, тому його можна безпечно кешувати і переобчислювати.
 */
final class SlotGridGenerator
{
    private readonly DateTimeZone $storeTimezone;
    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->storeTimezone = new DateTimeZone(StoreConfig::TIMEZONE);
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param string      $date             дата в локальному часі магазину, Y-m-d
     * @param string|null $viewerSupplierId постачальник, який дивиться сітку;
     *                                      null — співробітник мережі (бачить, за ким резерв)
     *
     * @throws DateOutOfHorizonException якщо дата виходить за горизонт бронювання
     */
    public function generate(
        StoreConfig $config,
        string $date,
        DateTimeImmutable $now,
        ?string $viewerSupplierId = null,
        ?SlotOverlays $overlays = null,
    ): SlotGrid {
        $this->assertDate($date);
        $overlays ??= SlotOverlays::empty();
        $now = $now->setTimezone($this->utc);

        $this->assertWithinHorizon($config, $date, $now);

        $intervals = $this->intervalsFor($config, $date);
        $slots = [];

        if ([] !== $intervals) {
            $dayOfWeek = (int) (new DateTimeImmutable($date, $this->storeTimezone))->format('N');
            $cutoff = $now->modify(\sprintf('+%d minutes', $config->leadTimeMinutes));

            foreach ($config->activeRamps() as $ramp) {
                foreach ($intervals as $interval) {
                    foreach ($this->sliceInterval($config, $date, $interval) as [$slotStart, $slotEnd, $localTime]) {
                        $slots[] = $this->buildSlot(
                            new SlotKey($config->storeId, $ramp->rampId, $slotStart),
                            $slotEnd,
                            $localTime,
                            $date,
                            $dayOfWeek,
                            $cutoff,
                            $viewerSupplierId,
                            $overlays,
                        );
                    }
                }
            }

            usort($slots, static function (Slot $a, Slot $b): int {
                return [$a->key->slotStart, $a->key->rampId] <=> [$b->key->slotStart, $b->key->rampId];
            });
        }

        return new SlotGrid(
            storeId: $config->storeId,
            date: $date,
            slots: $slots,
            maxVehicleWeightTons: $config->maxVehicleWeightTons,
            slotSizeMinutes: $config->slotSizeMinutes,
            leadTimeMinutes: $config->leadTimeMinutes,
            now: $now,
        );
    }

    /**
     * Нарізає інтервал вікна прийому на слоти розміру slotSizeMinutes.
     * Неповний «хвіст» інтервалу слотом не стає.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable, string}>
     */
    private function sliceInterval(StoreConfig $config, string $date, TimeInterval $interval): array
    {
        $slots = [];
        $size = $config->slotSizeMinutes;

        for ($minutes = $interval->fromMinutes; $minutes + $size <= $interval->toMinutes; $minutes += $size) {
            $localTime = \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $localStart = new DateTimeImmutable($date.' '.$localTime, $this->storeTimezone);

            // Перехід на літній час: локального часу 03:00–03:59 у ніч переведення
            // не існує, PHP зсуває його вперед. Такий слот не породжується (EDGE).
            if ($localStart->format('H:i') !== $localTime) {
                continue;
            }

            $localEnd = $localStart->modify(\sprintf('+%d minutes', $size));

            $slots[] = [
                $localStart->setTimezone($this->utc),
                $localEnd->setTimezone($this->utc),
                $localTime,
            ];
        }

        return $slots;
    }

    private function buildSlot(
        SlotKey $key,
        DateTimeImmutable $slotEnd,
        string $localTime,
        string $date,
        int $dayOfWeek,
        DateTimeImmutable $cutoff,
        ?string $viewerSupplierId,
        SlotOverlays $overlays,
    ): Slot {
        $reservedFor = $this->reservedSupplierFor($overlays, $date, $dayOfWeek, $localTime, $key->rampId);
        $reservedForViewer = null !== $reservedFor && $reservedFor === $viewerSupplierId;

        $candidates = [SlotState::Available];
        $blockReason = null;

        if (null !== $reservedFor && !$reservedForViewer) {
            $candidates[] = SlotState::Reserved;
        }

        if (isset($overlays->heldKeys[$key->toString()])) {
            $candidates[] = SlotState::Held;
        }

        if (isset($overlays->bookedKeys[$key->toString()])) {
            $candidates[] = SlotState::Booked;
        }

        foreach ($overlays->blocks as $block) {
            if ($block->covers($key, $slotEnd)) {
                $candidates[] = SlotState::Blocked;
                $blockReason = $block->reason;
                break;
            }
        }

        if ($key->slotStart < $cutoff) {
            $candidates[] = SlotState::Past;
        }

        $state = SlotState::Available;
        foreach ($candidates as $candidate) {
            if ($candidate->priority() > $state->priority()) {
                $state = $candidate;
            }
        }

        return new Slot(
            key: $key,
            slotEnd: $slotEnd,
            state: $state,
            reservedForViewer: $reservedForViewer && SlotState::Available === $state,
            // Чужі резерви постачальнику не розкриваються (GRID-04).
            reservedForSupplierId: null === $viewerSupplierId ? $reservedFor : null,
            blockReason: SlotState::Blocked === $state ? $blockReason : null,
        );
    }

    private function reservedSupplierFor(
        SlotOverlays $overlays,
        string $date,
        int $dayOfWeek,
        string $localTime,
        string $rampId,
    ): ?string {
        foreach ($overlays->reservedRules as $rule) {
            if ($rule->matches($date, $dayOfWeek, $localTime, $rampId)) {
                return $rule->supplierId;
            }
        }

        return null;
    }

    /**
     * @return list<TimeInterval>
     */
    private function intervalsFor(StoreConfig $config, string $date): array
    {
        $exception = $config->calendarExceptionFor($date);

        if (null !== $exception) {
            return $exception->closed ? [] : $exception->intervals;
        }

        $dayOfWeek = (int) (new DateTimeImmutable($date, $this->storeTimezone))->format('N');
        $window = $config->windowForDayOfWeek($dayOfWeek);

        return null === $window ? [] : $window->intervals;
    }

    private function assertWithinHorizon(StoreConfig $config, string $date, DateTimeImmutable $now): void
    {
        $today = $now->setTimezone($this->storeTimezone)->format('Y-m-d');
        $lastDate = (new DateTimeImmutable($today, $this->storeTimezone))
            ->modify(\sprintf('+%d days', $config->bookingHorizonDays))
            ->format('Y-m-d');

        // Минулі дати доступні для перегляду в store-web; горизонт обмежує лише майбутнє.
        if ($date > $lastDate) {
            throw new DateOutOfHorizonException($config->bookingHorizonDays);
        }
    }

    private function assertDate(string $date): void
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException(\sprintf('Дата має бути у форматі Y-m-d, отримано "%s"', $date));
        }
    }
}
