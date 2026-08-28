<?php

declare(strict_types=1);

namespace App\Tests\Domain\Service;

use App\Domain\Event\SupplierSuspended;
use App\Domain\Service\SupplierService;
use App\Domain\Shared\ConflictException;
use App\Domain\Shared\NotFoundException;
use App\Domain\Shared\ValidationException;
use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\SupplierContact;
use App\Domain\Supplier\SupplierStatus;
use App\Tests\Support\PartnerTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Адміністрування постачальників: SUP-01…SUP-06.
 */
#[CoversClass(SupplierService::class)]
final class SupplierServiceTest extends TestCase
{
    private PartnerTestEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new PartnerTestEnvironment();
    }

    /**
     * SUP-01: разом із контрагентом можна одразу завести вхід у кабінет.
     * Пароль повертається один раз — далі в сховищі лише хеш.
     */
    public function testSupplierCanBeCreatedWithLoginAndPassword(): void
    {
        $result = $this->env->supplierService->register(
            name: 'ТОВ «Дозвіл»',
            login: 'Dozvil@Rampa.UA',
            password: 'Nadiyn1yParol',
        );

        self::assertTrue($result->hasAccount());
        // Логін нормалізується: пошту шукають без огляду на регістр.
        self::assertSame('dozvil@rampa.ua', $result->login);
        self::assertSame('Nadiyn1yParol', $result->password);
        self::assertNotNull($this->env->suppliers->findById($result->supplier->id()));
    }

    /** Без пароля система згенерує його сама і поверне для передачі контрагенту. */
    public function testPasswordIsGeneratedWhenOnlyLoginGiven(): void
    {
        $result = $this->env->supplierService->register(
            name: 'ТОВ «Генерація»',
            login: 'gen@rampa.ua',
        );

        self::assertNotNull($result->password);
        self::assertNotSame('', $result->password);
    }

    /** Логін необовʼязковий: контрагента можна завести й без доступу. */
    public function testSupplierWithoutLoginHasNoAccount(): void
    {
        $result = $this->env->supplierService->register(name: 'ТОВ «Без входу»');

        self::assertFalse($result->hasAccount());
        self::assertNull($result->password);
    }

    /** Логін постачальника — робоча пошта, інакше його не знайти й не відновити. */
    public function testLoginMustBeAnEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('не схожий на адресу');

        $this->env->supplierService->register(name: 'ТОВ «Логін»', login: '380501234567');
    }

    /**
     * Зайнятий логін не має лишати напівстворений запис: інакше контрагента
     * не можна ні використати, ні завести повторно під тією ж назвою.
     */
    public function testSupplierIsNotSavedWhenLoginIsTaken(): void
    {
        $this->env->supplierService->register(name: 'Перший', login: 'zaynyato@rampa.ua');

        try {
            $this->env->supplierService->register(name: 'Другий', login: 'zaynyato@rampa.ua');
            self::fail('Очікувався конфлікт логіна');
        } catch (ConflictException) {
            // очікувано
        }

        self::assertNull($this->env->suppliers->findByName('Другий'));
    }

    public function testCreatesActiveSupplierWithAllStoresAccessByDefault(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»', '12345678');

        self::assertSame(SupplierStatus::Active, $supplier->status());
        self::assertTrue($supplier->isActive());
        self::assertTrue($supplier->storeAccess()->allStores);
        self::assertSame('12345678', $supplier->edrpou());
    }

    /**
     * SUP-01: назва унікальна (без урахування регістру).
     */
    public function testSupplierNameMustBeUnique(): void
    {
        $this->env->supplierService->create('ТОВ «Логістик Плюс»');

        try {
            $this->env->supplierService->create('тов «логістик плюс»');
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('SUPPLIER_NAME_DUPLICATE', $e->errorCode());
        }
    }

    /**
     * SUP-01: ЄДРПОУ унікальний.
     */
    public function testEdrpouMustBeUnique(): void
    {
        $this->env->supplierService->create('Перший', '12345678');

        try {
            $this->env->supplierService->create('Другий', '12345678');
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('SUPPLIER_EDRPOU_DUPLICATE', $e->errorCode());
        }
    }

    public function testSuppliersWithoutEdrpouDoNotClash(): void
    {
        $first = $this->env->supplierService->create('Перший');
        $second = $this->env->supplierService->create('Другий');

        self::assertNull($first->edrpou());
        self::assertNull($second->edrpou());
        self::assertSame(2, $this->env->supplierService->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEdrpou(): iterable
    {
        yield 'сім цифр' => ['1234567'];
        yield 'девʼять цифр' => ['123456789'];
        yield 'одинадцять цифр' => ['12345678901'];
        yield 'з літерами' => ['1234567A'];
    }

    /**
     * SUP-01: код ЄДРПОУ — 8 або 10 цифр.
     */
    #[DataProvider('invalidEdrpou')]
    public function testInvalidEdrpouIsRejected(string $edrpou): void
    {
        $this->expectException(ValidationException::class);

        $this->env->supplierService->create('Перший', $edrpou);
    }

    public function testTenDigitEdrpouIsAcceptedForSoleProprietors(): void
    {
        $supplier = $this->env->supplierService->create('ФОП Петренко', '1234567890');

        self::assertSame('1234567890', $supplier->edrpou());
    }

    /**
     * SUP-02: suspend блокує логіни і публікує канонічну подію.
     */
    public function testSuspendBlocksAccountsAndPublishesCanonicalEvent(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');
        $this->env->driverService->createDriver($supplier->id(), '+380671112233', 'Іван', 'Коваль');
        $accountId = (string) $this->env->users->findDriverByPhone('+380671112233')?->accountId();

        $suspended = $this->env->supplierService->suspend($supplier->id(), 'Заборгованість');

        self::assertSame(SupplierStatus::Suspended, $suspended->status());
        self::assertFalse($suspended->isActive());
        self::assertSame('Заборгованість', $suspended->suspendReason());
        self::assertFalse($this->env->accounts->isActive($accountId), 'SUP-02: логін водія має бути заблокований.');

        $events = $this->env->events->ofType('SupplierSuspended');
        self::assertCount(1, $events);
        self::assertInstanceOf(SupplierSuspended::class, $events[0]);
        self::assertSame($supplier->id(), $events[0]->aggregateId());
        self::assertSame('Заборгованість', $events[0]->payload()['reason']);
    }

    /**
     * Повторний suspend не має породжувати другу подію —
     * інакше notification-service розсилатиме дублікати.
     */
    public function testRepeatedSuspendIsIdempotent(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');

        $this->env->supplierService->suspend($supplier->id(), 'Перша причина');
        $this->env->supplierService->suspend($supplier->id(), 'Друга причина');

        self::assertCount(1, $this->env->events->ofType('SupplierSuspended'));
        self::assertSame('Перша причина', $this->env->supplierService->get($supplier->id())->suspendReason());
    }

    public function testActivationRestoresSupplierAndAccounts(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');
        $credentials = $this->env->driverService->createDriver($supplier->id(), '+380671112233', 'Іван', 'Коваль');
        $this->env->supplierService->suspend($supplier->id());

        $activated = $this->env->supplierService->activate($supplier->id());

        self::assertTrue($activated->isActive());
        self::assertNull($activated->suspendReason());
        self::assertTrue($this->env->accounts->isActive($credentials->driver->accountId()));
    }

    /**
     * SUP-06: постачальника з історією бронювань видалити не можна.
     */
    public function testSupplierWithBookingsCannotBeDeleted(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');
        $this->env->bookings->registerSupplierBooking($supplier->id());

        try {
            $this->env->supplierService->delete($supplier->id());
            self::fail('Очікувався ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame('SUPPLIER_HAS_BOOKINGS', $e->errorCode());
            self::assertSame('Постачальника з історією бронювань не можна видалити.', $e->getMessage());
        }

        self::assertTrue($this->env->supplierService->get($supplier->id())->isActive());
    }

    public function testSupplierWithoutBookingsIsArchivedOnDelete(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');

        $this->env->supplierService->delete($supplier->id());

        self::assertNotNull($this->env->supplierService->get($supplier->id())->archivedAt());
        self::assertSame(0, $this->env->supplierService->count(), 'Архівований постачальник зникає зі списків.');
    }

    /**
     * SUP-06: після видалення картка недосяжна і за прямим посиланням —
     * інакше видаленого постачальника можна було відкрити, перейменувати
     * й «активувати», повернувши його в роботу повз список.
     */
    public function testDeletedSupplierIsNotReachableByAdminContour(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');
        $this->env->supplierService->delete($supplier->id());

        foreach (
            [
                'читання картки' => fn () => $this->env->supplierService->getManaged($supplier->id()),
                'перейменування' => fn () => $this->env->supplierService->rename($supplier->id(), 'ТОВ «Інше»'),
                'активація' => fn () => $this->env->supplierService->activate($supplier->id()),
                'повторне видалення' => fn () => $this->env->supplierService->delete($supplier->id()),
            ] as $case => $action
        ) {
            try {
                $action();
                self::fail(\sprintf('Очікувався SUPPLIER_NOT_FOUND: %s.', $case));
            } catch (NotFoundException $e) {
                self::assertSame('SUPPLIER_NOT_FOUND', $e->errorCode(), $case);
            }
        }

        // Службовий контур бачить архівний запис і трактує його як призупинений
        // (DATA-03) — booking-service має відповісти «немає доступу», а не 404.
        self::assertSame(
            SupplierStatus::Suspended,
            $this->env->supplierService->accessSnapshot($supplier->id())->status,
        );
    }

    /**
     * SUP-03: whitelist філій.
     */
    public function testStoreWhitelistLimitsVisibleStores(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');

        $updated = $this->env->supplierService->changeStoreAccess(
            $supplier->id(),
            StoreAccess::whitelist(['st-1998', 'st-2001']),
        );

        self::assertFalse($updated->storeAccess()->allStores);
        self::assertTrue($updated->storeAccess()->allows('st-1998'));
        self::assertFalse($updated->storeAccess()->allows('st-3000'));
    }

    public function testEmptyWhitelistIsRejectedBecauseItWouldMeanNoStores(): void
    {
        $this->expectException(ValidationException::class);

        StoreAccess::whitelist([]);
    }

    public function testContactPhonesAreNormalizedOnSave(): void
    {
        $supplier = $this->env->supplierService->create('ТОВ «Логістик Плюс»');

        $updated = $this->env->supplierService->replaceContacts($supplier->id(), [
            SupplierContact::fromArray([
                'name' => 'Олена Мельник',
                'phone' => '050 123 45 67',
                'email' => 'olena@example.com',
            ]),
        ]);

        self::assertCount(1, $updated->contacts());
        self::assertSame('+380501234567', $updated->contacts()[0]->phone);
    }

    public function testInvalidContactEmailIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        SupplierContact::fromArray(['name' => 'Олена', 'email' => 'не-пошта']);
    }

    public function testRenameKeepsUniquenessCheckButAllowsSameSupplier(): void
    {
        $first = $this->env->supplierService->create('Перший');
        $this->env->supplierService->create('Другий');

        $renamed = $this->env->supplierService->rename($first->id(), 'Перший');
        self::assertSame('Перший', $renamed->name());

        $this->expectException(ConflictException::class);
        $this->env->supplierService->rename($first->id(), 'Другий');
    }

    public function testChangingEdrpouToOneUsedByAnotherSupplierIsRejected(): void
    {
        $first = $this->env->supplierService->create('Перший', '11111111');
        $this->env->supplierService->create('Другий', '22222222');

        $this->expectException(ConflictException::class);

        $this->env->supplierService->changeEdrpou($first->id(), '22222222');
    }

    public function testChangingEdrpouToItsOwnValueIsAllowed(): void
    {
        $first = $this->env->supplierService->create('Перший', '11111111');

        $updated = $this->env->supplierService->changeEdrpou($first->id(), '11111111');

        self::assertSame('11111111', $updated->edrpou());
    }

    public function testEdrpouCanBeCleared(): void
    {
        $first = $this->env->supplierService->create('Перший', '11111111');

        $updated = $this->env->supplierService->changeEdrpou($first->id(), null);

        self::assertNull($updated->edrpou());
    }

    public function testUnknownSupplierProducesNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->env->supplierService->get('sp-невідомий');
    }

    public function testSearchFiltersByStatusAndQuery(): void
    {
        $active = $this->env->supplierService->create('Альфа Транс', '11111111');
        $this->env->supplierService->create('Бета Логістика', '22222222');
        $suspended = $this->env->supplierService->create('Гамма Карго', '33333333');
        $this->env->supplierService->suspend($suspended->id());

        self::assertCount(3, $this->env->supplierService->search());
        self::assertCount(2, $this->env->supplierService->search(status: SupplierStatus::Active));
        self::assertCount(1, $this->env->supplierService->search(status: SupplierStatus::Suspended));
        self::assertSame(
            $active->id(),
            $this->env->supplierService->search(query: 'альфа')[0]->id(),
        );
        self::assertCount(1, $this->env->supplierService->search(query: '22222222'));
    }
}
