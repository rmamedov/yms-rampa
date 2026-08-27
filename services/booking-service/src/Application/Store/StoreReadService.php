<?php

declare(strict_types=1);

namespace App\Application\Store;

use App\Application\Slot\SlotGridService;
use App\Domain\Access\Actor;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingRepository;
use App\Domain\Driver\DriverDirectory;
use App\Domain\Exception\ValidationFailedException;
use App\Domain\Store\StoreBrief;
use App\Domain\Store\StoreDirectory;
use App\Domain\Store\StoreSettings;
use App\Domain\Supplier\SupplierDirectory;
use App\Domain\Supplier\SupplierInfo;
use DateTimeImmutable;

/**
 * Читання контуру магазину: перелік філій, конфігурація, довідник
 * постачальників, дошка прибуттів і сітка слотів очима персоналу.
 *
 * ПРАВА. Кожен сценарій починається з тієї самої перевірки, що й дії
 * ST-01..ST-07: Actor::assertCanOperateStore(). Читання і дії свідомо не
 * розведені на різні правила — інакше зʼявився б екран, який показує те,
 * чого не можна змінити (або навпаки). Наслідки, які з цього випливають:
 *
 *   - store_manager / store_operator бачать лише магазини зі скоупу
 *     (заголовок X-Store-Ids), чужий магазин — 403 ACCESS_DENIED;
 *   - порожній скоуп магазинної ролі = нуль магазинів (RBAC-13);
 *   - super_admin / network_manager працюють у будь-якій філії (RBAC-16),
 *     і це вимога канонічної матриці прав: booking.read.all = ✓ ✓;
 *   - постачальники і водії сюди не входять — у них власні контури.
 */
final readonly class StoreReadService
{
    /** Стеля вікна «Розклад тижня»; більший діапазон — помилка клієнта. */
    public const int MAX_DAYS = 14;

    public function __construct(
        private SlotGridService $grid,
        private BookingRepository $bookings,
        private SupplierDirectory $suppliers,
        private DriverDirectory $drivers,
        private StoreDirectory $stores,
    ) {
    }

    /**
     * Магазини, доступні користувачеві.
     *
     * Порожній скоуп магазинної ролі обробляється ТУТ і до сусіда не доходить:
     * «немає жодного магазину» — це відповідь, яку booking-service знає сам, і
     * запит без звуження ризикував би повернути всю мережу.
     *
     * @return list<StoreBrief>
     */
    public function stores(Actor $actor): array
    {
        $actor->assertCanOperateAnyStore();

        // Мережеві ролі і планові завдання скоупом не обмежені (RBAC-16).
        if ($actor->system || $actor->role->isNetworkAdmin()) {
            return $this->stores->listStores(null);
        }

        if ([] === $actor->storeIds) {
            return [];
        }

        return $this->stores->listStores($actor->storeIds);
    }

    /** Конфігурація філії: рампи, вікна прийому, розмір слота, ліміт тоннажу. */
    public function config(Actor $actor, string $storeId): StoreSettings
    {
        $actor->assertCanOperateStore($storeId);

        return $this->grid->settingsFor($storeId, $actor);
    }

    /**
     * Довідник постачальників для форми позапланового прибуття.
     *
     * @return list<SupplierInfo>
     */
    public function suppliers(Actor $actor, string $storeId): array
    {
        $actor->assertCanOperateStore($storeId);

        // Магазин має існувати: інакше довідник «для неіснуючої філії» мовчки
        // повернув би порожній перелік замість 404.
        $this->grid->settingsFor($storeId, $actor);

        return $this->suppliers->listForStore($storeId);
    }

    /** Дошка прибуттів магазину на локальну добу. */
    public function board(Actor $actor, string $storeId, string $date, DateTimeImmutable $now): StoreBoard
    {
        // Відсутній storeId — помилка запиту, а не відмова в доступі: інакше
        // клієнт із забутим параметром отримав би 403 і шукав проблему в правах.
        if ('' === trim($storeId)) {
            throw new ValidationFailedException('Параметр «storeId» обовʼязковий');
        }

        $actor->assertCanOperateStore($storeId);
        self::assertDate($date);

        // Філія має існувати і бути підключеною до YMS — інакше дошка
        // «порожня», хоча насправді магазин просто не той (STORE_NOT_FOUND).
        $this->grid->settingsFor($storeId, $actor);

        [$from, $to] = SlotGridService::localDayRange($date);
        $bookings = $this->bookings->findByStoreAndRange($storeId, $from, $to);

        return new StoreBoard(
            storeId: $storeId,
            date: $date,
            bookings: $bookings,
            drivers: $this->drivers->findMany(self::driverIds($bookings)),
            now: $now,
        );
    }

    /** Сітка слотів магазину на одну добу. */
    public function slots(Actor $actor, string $storeId, string $date, DateTimeImmutable $now): StaffSlotDay
    {
        $actor->assertCanOperateStore($storeId);
        self::assertDate($date);

        return $this->buildDay($this->grid->settingsFor($storeId, $actor), $date, $now);
    }

    /**
     * Сітка слотів на кілька діб поспіль — екран «Розклад тижня».
     *
     * Конфігурація магазину читається ОДИН раз на весь діапазон: інакше тиждень
     * коштував би семи звернень до store-service замість одного.
     *
     * @return list<StaffSlotDay>
     */
    public function week(
        Actor $actor,
        string $storeId,
        string $from,
        int $days,
        DateTimeImmutable $now,
    ): array {
        $actor->assertCanOperateStore($storeId);
        self::assertDate($from);

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new ValidationFailedException(\sprintf(
                'Параметр «days» має бути в діапазоні 1..%d, отримано %d',
                self::MAX_DAYS,
                $days,
            ));
        }

        $settings = $this->grid->settingsFor($storeId, $actor);
        $result = [];

        for ($offset = 0; $offset < $days; ++$offset) {
            $result[] = $this->buildDay($settings, self::shiftDate($from, $offset), $now);
        }

        return $result;
    }

    private function buildDay(StoreSettings $settings, string $date, DateTimeImmutable $now): StaffSlotDay
    {
        // viewerSupplierId = null: персонал бачить чужі резерви (GRID-04
        // ховає їх саме від постачальника), тому підставляти сюди актора,
        // як це робить SlotGridService::grid(), не можна.
        $grid = $this->grid->build($settings, $date, null, $now);

        [$from, $to] = SlotGridService::localDayRange($date);
        $bookingIds = [];

        foreach ($this->bookings->findByStoreAndRange($settings->storeId(), $from, $to) as $booking) {
            // Ключ слота звільняється разом зі скасуванням (EDIT-03), тому
            // клітинку підписує лише активне бронювання — те саме, що робить
            // сітку «зайнятою».
            if ($booking->isActive()) {
                $bookingIds[$booking->slotKey()->toString()] = $booking->id;
            }
        }

        return new StaffSlotDay($date, $grid, $bookingIds);
    }

    /**
     * @param list<Booking> $bookings
     *
     * @return list<string>
     */
    private static function driverIds(array $bookings): array
    {
        $ids = [];

        foreach ($bookings as $booking) {
            $driverId = $booking->driverId();

            if (null !== $driverId && '' !== $driverId) {
                $ids[$driverId] = true;
            }
        }

        return array_keys($ids);
    }

    private static function assertDate(string $date): void
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationFailedException(
                'Параметр дати обовʼязковий і має бути у форматі YYYY-MM-DD'
            );
        }
    }

    /** Локальна дата магазину, зміщена на N діб (без пасток переходу на літній час). */
    private static function shiftDate(string $date, int $days): string
    {
        return (new DateTimeImmutable($date.' 12:00:00'))
            ->modify(\sprintf('+%d days', $days))
            ->format('Y-m-d');
    }
}
