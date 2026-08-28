<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Booking\BookingQueryPort;
use App\Domain\Event\EventPublisher;
use App\Domain\Event\SupplierSuspended;
use App\Domain\Identity\PartnerAccountGateway;
use App\Domain\Shared\Clock;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\IdGenerator;
use App\Domain\Shared\NotFoundException;
use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierAccessSnapshot;
use App\Domain\Supplier\SupplierContact;
use App\Domain\Supplier\SupplierRepository;
use App\Domain\Supplier\SupplierStatus;

/**
 * Сценарії адміністрування постачальників (розділ 5.4: SUP-01…SUP-06).
 *
 * Клас чисто доменний: жодних залежностей від Symfony, MongoDB чи HTTP —
 * лише порти (репозиторій, шлюз ідентичності, публікатор подій, годинник).
 */
final readonly class SupplierService
{
    public function __construct(
        private SupplierRepository $suppliers,
        private PartnerAccountGateway $accounts,
        private EventPublisher $events,
        private BookingQueryPort $bookings,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    /**
     * SUP-01: створення постачальника. Назва унікальна, ЄДРПОУ (якщо вказано) —
     * теж унікальний (unique partial index за розділом 10.4).
     *
     * @param list<SupplierContact> $contacts
     */
    public function create(
        string $name,
        ?string $edrpou = null,
        ?StoreAccess $storeAccess = null,
        array $contacts = [],
    ): Supplier {
        $now = $this->clock->now();

        $supplier = new Supplier(
            id: $this->ids->generate(),
            name: $name,
            edrpou: $edrpou,
            storeAccess: $storeAccess,
            contacts: $contacts,
            createdAt: $now,
        );

        $this->assertNameFree($supplier->name(), null);
        $this->assertEdrpouFree($supplier->edrpou(), null);

        $this->suppliers->save($supplier);

        return $supplier;
    }

    public function get(string $id): Supplier
    {
        return $this->suppliers->findById($id)
            ?? throw new NotFoundException(
                \sprintf('Постачальника «%s» не знайдено.', $id),
                'SUPPLIER_NOT_FOUND',
            );
    }

    /**
     * Знімок постачальника для booking-service (BOOK-02): статус і прив'язка
     * до магазинів у формі, придатній для перевірки права бронювати.
     *
     * Неіснуючий постачальник — NotFoundException з кодом SUPPLIER_NOT_FOUND,
     * як і в адмін-API: службовий виклик не має власного трактування «немає».
     */
    public function accessSnapshot(string $id): SupplierAccessSnapshot
    {
        return SupplierAccessSnapshot::fromSupplier($this->get($id));
    }

    /**
     * Перевірка унікальності виконується ДО мутації агрегату: інакше вже
     * переназваний постачальник сам став би «знайденим дублікатом» і
     * приховав справжній конфлікт.
     */
    public function rename(string $id, string $name): Supplier
    {
        $supplier = $this->get($id);
        $this->assertNameFree(Supplier::normalizeName($name), $supplier->id());
        $supplier->rename($name, $this->clock->now());
        $this->suppliers->save($supplier);

        return $supplier;
    }

    public function changeEdrpou(string $id, ?string $edrpou): Supplier
    {
        $supplier = $this->get($id);
        $this->assertEdrpouFree(Supplier::normalizeEdrpou($edrpou), $supplier->id());
        $supplier->changeEdrpou($edrpou, $this->clock->now());
        $this->suppliers->save($supplier);

        return $supplier;
    }

    /**
     * SUP-03: перемикання між режимом «всі магазини» і whitelist філій.
     */
    public function changeStoreAccess(string $id, StoreAccess $storeAccess): Supplier
    {
        $supplier = $this->get($id);
        $supplier->changeStoreAccess($storeAccess, $this->clock->now());
        $this->suppliers->save($supplier);

        return $supplier;
    }

    /**
     * @param list<SupplierContact> $contacts
     */
    public function replaceContacts(string $id, array $contacts): Supplier
    {
        $supplier = $this->get($id);
        $supplier->replaceContacts($contacts, $this->clock->now());
        $this->suppliers->save($supplier);

        return $supplier;
    }

    /**
     * SUP-02: призупинення постачальника.
     *
     * Побічні ефекти виконуються рівно один раз (повторний виклик для вже
     * призупиненого постачальника — no-op): блокування всіх акаунтів контуру
     * identity-partner-service і публікація канонічної події SupplierSuspended.
     * Чинні бронювання зберігаються і залишаються видимими магазинам.
     */
    public function suspend(string $id, ?string $reason = null): Supplier
    {
        $supplier = $this->get($id);
        $now = $this->clock->now();

        if (!$supplier->suspend($now, $reason)) {
            return $supplier;
        }

        $this->suppliers->save($supplier);
        $this->accounts->setSupplierAccountsActive($supplier->id(), false);
        $this->events->publish(new SupplierSuspended(
            supplierId: $supplier->id(),
            supplierName: $supplier->name(),
            reason: $supplier->suspendReason(),
            occurredAt: $now,
        ));

        return $supplier;
    }

    /**
     * Зворотна дія до SUP-02: логіни знову дозволені.
     * Окремої канонічної події для відновлення реєстр не передбачає.
     */
    public function activate(string $id): Supplier
    {
        $supplier = $this->get($id);

        if (!$supplier->activate($this->clock->now())) {
            return $supplier;
        }

        $this->suppliers->save($supplier);
        $this->accounts->setSupplierAccountsActive($supplier->id(), true);

        return $supplier;
    }

    /**
     * SUP-06: видалення заборонене, якщо існує хоч одне бронювання
     * будь-якого статусу — доступне лише переведення в suspended.
     */
    public function delete(string $id): void
    {
        $supplier = $this->get($id);

        if ($this->bookings->supplierHasAnyBookings($supplier->id())) {
            throw new ConflictException(
                'Постачальника з історією бронювань не можна видалити.',
                'SUPPLIER_HAS_BOOKINGS',
            );
        }

        $supplier->archive($this->clock->now());
        $this->suppliers->save($supplier);
        $this->accounts->setSupplierAccountsActive($supplier->id(), false);
    }

    /**
     * @return list<Supplier>
     */
    public function search(
        ?string $query = null,
        ?SupplierStatus $status = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        return $this->suppliers->search($query, $status, $limit, $offset);
    }

    public function count(?string $query = null, ?SupplierStatus $status = null): int
    {
        return $this->suppliers->count($query, $status);
    }

    /**
     * Сторінка службового довідника постачальників для booking-service
     * (форма позапланового прибуття, WALK-01).
     *
     * Вибірка джерела — лише АКТИВНІ постачальники (SUP-02): призупиненого
     * не можна обрати в новому прибутті так само, як не можна забронювати.
     *
     * `total` рахує саме джерело, а не відфільтрований результат: предикат
     * доступу до філії (SUP-03) — доменне правило знімка, а не запит до
     * сховища, тож чесно порахувати відфільтровану кількість без повного
     * сканування неможливо. Тому клієнт гортає сторінки за `hasMore`, а не
     * за кількістю отриманих елементів — інакше він зупинився б на першій же
     * сторінці, де фільтр не пропустив нікого.
     *
     * @param string|null $storeId філія, для якої добирається постачальник;
     *                             null — без перевірки доступу до філії
     *
     * @return array{items: list<SupplierAccessSnapshot>, total: int, limit: int, offset: int, hasMore: bool}
     */
    /**
     * @param bool $activeOnly false — віддати і призупинених. Довіднику
     *                         позапланового прибуття потрібні саме всі:
     *                         призупинений постачальник цілком може під'їхати
     *                         до рампи, і це якраз той випадок, який найважливіше
     *                         зафіксувати за контрагентом, а не «поза системою».
     *                         Статус їде у відповіді, тож інтерфейс може його показати.
     */
    public function catalogPage(
        ?string $storeId = null,
        int $limit = 100,
        int $offset = 0,
        bool $activeOnly = true,
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $status = $activeOnly ? SupplierStatus::Active : null;

        $snapshots = array_map(
            SupplierAccessSnapshot::fromSupplier(...),
            $this->suppliers->search(null, $status, $limit, $offset),
        );

        if (null !== $storeId && '' !== $storeId) {
            $snapshots = array_values(array_filter(
                $snapshots,
                static fn (SupplierAccessSnapshot $s): bool => $s->allows($storeId),
            ));
        }

        $total = $this->suppliers->count(null, $status);

        return [
            'items' => $snapshots,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    private function assertNameFree(string $name, ?string $exceptId): void
    {
        $existing = $this->suppliers->findByName($name);

        if (null !== $existing && $existing->id() !== $exceptId) {
            throw new ConflictException(
                \sprintf('Постачальник із назвою «%s» уже існує.', $name),
                'SUPPLIER_NAME_DUPLICATE',
            );
        }
    }

    private function assertEdrpouFree(?string $edrpou, ?string $exceptId): void
    {
        if (null === $edrpou) {
            return;
        }

        $existing = $this->suppliers->findByEdrpou($edrpou);

        if (null !== $existing && $existing->id() !== $exceptId) {
            throw new ConflictException(
                \sprintf('Постачальник із кодом ЄДРПОУ «%s» уже існує.', $edrpou),
                'SUPPLIER_EDRPOU_DUPLICATE',
            );
        }
    }
}
