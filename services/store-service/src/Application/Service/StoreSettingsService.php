<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Application\Dto\StoreSettingsPresenter;
use App\Domain\Branch\Branch;
use App\Domain\Branch\YmsStatus;
use App\Domain\Configuration\ReservedSlotRule;
use App\Domain\Configuration\ReservedSlotRuleRepository;
use App\Domain\Configuration\SlotBlockRepository;
use App\Domain\Configuration\StoreConfiguration;
use App\Domain\Shared\Clock;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\Timezone;

/**
 * Чинна конфігурація магазину для booking-service (службовий контур).
 *
 * booking-service будує сітку слотів і валідує бронювання виключно за цими даними,
 * тому магазин, для якого сітки існувати не повинно, тут не «повертається порожнім»,
 * а НЕ ІСНУЄ (404): інакше сервіс мовчки згенерує сітку по неактивній філії.
 * Не існує магазин, який:
 *
 *   - відсутній у довіднику                       → STORE_NOT_FOUND;
 *   - має ymsStatus ≠ active (STC-03..STC-06)     → STORE_NOT_CONFIGURED;
 *   - не має чинної версії конфігурації на зараз  → STORE_NOT_CONFIGURED;
 *   - має неповну конфігурацію за STL-04          → STORE_NOT_CONFIGURED.
 */
final readonly class StoreSettingsService
{
    public function __construct(
        private StoreCatalogService $catalog,
        private ReservedSlotRuleRepository $reservedRules,
        private SlotBlockRepository $slotBlocks,
        private Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(string $storeId): array
    {
        $branch = $this->catalog->requireBranch($storeId);

        $this->assertActive($branch);

        $now = $this->clock->now();
        $configuration = $this->catalog->effectiveConfig($storeId, $now);

        if (!$configuration instanceof StoreConfiguration) {
            throw NotFoundException::storeNotConfigured(
                $storeId,
                'немає чинної версії конфігурації прийому',
            );
        }

        $readiness = $configuration->readiness();

        if (!$readiness->complete) {
            throw NotFoundException::storeNotConfigured(
                $storeId,
                'не задано '.$readiness->missingAsText(),
            );
        }

        $horizonEnd = self::horizonEnd($now, $configuration->bookingHorizonDays);

        return StoreSettingsPresenter::settings(
            branch: $branch,
            configuration: $configuration,
            reservedRules: $this->effectiveRules($storeId, $now, $horizonEnd),
            // findOverlapping уже відкидає зняті блокування (STC-52) і ті, що не
            // перетинають горизонт: звільнений слот знову доступний до бронювання.
            slotBlocks: $this->slotBlocks->findOverlapping($storeId, $now, $horizonEnd),
        );
    }

    private function assertActive(Branch $branch): void
    {
        if (YmsStatus::Active === $branch->ymsStatus()) {
            return;
        }

        throw NotFoundException::storeNotConfigured(
            $branch->id(),
            \sprintf('магазин у статусі «%s», бронювання недоступне', $branch->ymsStatus()->label()),
        );
    }

    /**
     * Чинні правила резервів: активні і з періодом дії, що перетинає горизонт
     * бронювання. Правило, яке вже закінчилося або почнеться за горизонтом,
     * на жоден слот сітки не впливає — і лише роздуває відповідь.
     *
     * @return list<ReservedSlotRule>
     */
    private function effectiveRules(string $storeId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $fromDate = Timezone::localDate($from);
        $toDate = Timezone::localDate($to);

        return array_values(array_filter(
            $this->reservedRules->findForStore($storeId, true),
            static function (ReservedSlotRule $rule) use ($from, $to, $fromDate, $toDate): bool {
                if (null !== $rule->validTo && $rule->validTo < $from) {
                    return false;
                }

                if ($rule->validFrom > $to) {
                    return false;
                }

                // Разовий резерв (DATA-33) поза горизонтом сітку не змінює.
                return null === $rule->date || ($rule->date >= $fromDate && $rule->date <= $toDate);
            },
        ));
    }

    /**
     * Кінець горизонту — локальна північ ПІСЛЯ останньої бронювальної доби,
     * щоб у вибірку потрапило блокування, яке починається пізно ввечері цього дня.
     */
    private static function horizonEnd(\DateTimeImmutable $now, int $bookingHorizonDays): \DateTimeImmutable
    {
        return $now
            ->setTimezone(Timezone::storeLocal())
            ->modify(\sprintf('+%d days', $bookingHorizonDays))
            ->modify('tomorrow midnight')
            ->setTimezone(Timezone::storage());
    }
}
