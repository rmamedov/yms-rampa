<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Service\DriverService;
use App\Domain\Service\SupplierService;
use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\Supplier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Службові довідники для booking-service:
 *   GET /internal/v1/suppliers?storeId=…  — довідник форми walk-in;
 *   GET /internal/v1/drivers?ids=…        — ПІБ і телефон у картці прибуття.
 *
 * Запити подаються прямо в ядро на InMemory-сховищах — ані вебсервера,
 * ані MongoDB, ані мережі тест не потребує.
 */
final class InternalDirectoryApiTest extends KernelTestCase
{
    private const string STORE_ID = 'S-01';

    private KernelInterface $httpKernel;
    private SupplierService $suppliers;
    private DriverService $drivers;

    protected function setUp(): void
    {
        $this->httpKernel = self::bootKernel();

        /** @var SupplierService $suppliers */
        $suppliers = self::getContainer()->get(SupplierService::class);
        $this->suppliers = $suppliers;

        /** @var DriverService $drivers */
        $drivers = self::getContainer()->get(DriverService::class);
        $this->drivers = $drivers;
    }

    // --- Довідник постачальників -------------------------------------------

    public function testSupplierListReturnsAccessSnapshots(): void
    {
        $this->givenSupplier('ТОВ «Логістик Плюс»');

        $payload = self::decode($this->get('/internal/v1/suppliers?storeId='.self::STORE_ID));

        self::assertSame(['items', 'total', 'limit', 'offset', 'hasMore'], array_keys($payload));
        self::assertCount(1, $payload['items']);
        self::assertSame(
            ['supplierId', 'name', 'status', 'allStores', 'allowedStoreIds'],
            array_keys($payload['items'][0]),
        );
        self::assertSame('ТОВ «Логістик Плюс»', $payload['items'][0]['name']);
        self::assertFalse($payload['hasMore']);
    }

    /** SUP-03: постачальник із чужим whitelist для цієї філії не пропонується. */
    public function testSupplierListRespectsStoreWhitelist(): void
    {
        $this->givenSupplier('ТОВ «Наш»', StoreAccess::whitelist([self::STORE_ID]));
        $this->givenSupplier('ТОВ «Чужий»', StoreAccess::whitelist(['S-99']));

        $payload = self::decode($this->get('/internal/v1/suppliers?storeId='.self::STORE_ID));

        self::assertSame(['ТОВ «Наш»'], array_column($payload['items'], 'name'));
    }

    /** SUP-02: призупиненого постачальника обрати не можна — його немає в списку. */
    public function testSupplierListHidesSuspendedSuppliers(): void
    {
        $active = $this->givenSupplier('ТОВ «Активний»');
        $suspended = $this->givenSupplier('ТОВ «Призупинений»');
        $this->suppliers->suspend($suspended->id(), 'Заборгованість');

        $payload = self::decode($this->get('/internal/v1/suppliers?storeId='.self::STORE_ID));

        self::assertSame([$active->id()], array_column($payload['items'], 'supplierId'));
    }

    /** Без storeId маршрут віддає всіх активних — фільтра за філією просто немає. */
    public function testSupplierListWithoutStoreReturnsEveryActiveSupplier(): void
    {
        $this->givenSupplier('ТОВ «Один»', StoreAccess::whitelist(['S-99']));
        $this->givenSupplier('ТОВ «Два»');

        self::assertCount(2, self::decode($this->get('/internal/v1/suppliers'))['items']);
    }

    /**
     * `hasMore` рахується від ДЖЕРЕЛА вибірки: сторінка, з якої фільтр за
     * філією нікого не пропустив, не має зупиняти клієнта.
     */
    public function testHasMoreIsDrivenBySourceNotByFilteredItems(): void
    {
        $this->givenSupplier('ААА Чужий 1', StoreAccess::whitelist(['S-99']));
        $this->givenSupplier('БББ Чужий 2', StoreAccess::whitelist(['S-99']));
        $this->givenSupplier('ВВВ Наш', StoreAccess::whitelist([self::STORE_ID]));

        $first = self::decode($this->get('/internal/v1/suppliers?storeId='.self::STORE_ID.'&limit=1&offset=0'));

        self::assertSame([], $first['items']);
        self::assertTrue($first['hasMore'], 'Порожня сторінка не означає кінець переліку');
        self::assertSame(3, $first['total']);

        $last = self::decode($this->get('/internal/v1/suppliers?storeId='.self::STORE_ID.'&limit=1&offset=2'));

        self::assertCount(1, $last['items']);
        self::assertFalse($last['hasMore']);
    }

    // --- Довідник водіїв ----------------------------------------------------

    public function testDriverListReturnsNameAndPhone(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');
        $driver = $this->drivers->createDriver(
            supplierId: $supplier->id(),
            phone: '+380671234567',
            firstName: 'Іван',
            lastName: 'Іваненко',
        );

        $payload = self::decode($this->get('/internal/v1/drivers?ids='.$driver->driver->id()));

        self::assertCount(1, $payload['items']);
        self::assertSame(
            ['driverId', 'fullName', 'phone', 'supplierId', 'active'],
            array_keys($payload['items'][0]),
        );
        self::assertSame('Іваненко Іван', $payload['items'][0]['fullName']);
        self::assertSame('+380671234567', $payload['items'][0]['phone']);
        self::assertTrue($payload['items'][0]['active']);
    }

    /** Пакетний запит: дошка магазину читає всіх водіїв доби одним викликом. */
    public function testDriverListAcceptsSeveralIdsAtOnce(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');
        $first = $this->drivers->createDriver($supplier->id(), '+380671111111', 'Іван', 'Перший');
        $second = $this->drivers->createDriver($supplier->id(), '+380672222222', 'Петро', 'Другий');

        $payload = self::decode($this->get(\sprintf(
            '/internal/v1/drivers?ids=%s,%s',
            $first->driver->id(),
            $second->driver->id(),
        )));

        self::assertSame(
            ['Перший Іван', 'Другий Петро'],
            array_column($payload['items'], 'fullName'),
        );
    }

    /** Невідомий профіль просто відсутній — 404 тут не буває. */
    public function testUnknownDriverIsAbsentInsteadOfNotFound(): void
    {
        $response = $this->get('/internal/v1/drivers?ids=du-zniklyi');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([], self::decode($response)['items']);
    }

    public function testEmptyIdsReturnEmptyList(): void
    {
        self::assertSame([], self::decode($this->get('/internal/v1/drivers'))['items']);
    }

    /** Службові маршрути не потребують заголовків ідентичності. */
    public function testInternalDirectoriesDoNotRequireIdentityHeaders(): void
    {
        self::assertSame(Response::HTTP_OK, $this->get('/internal/v1/suppliers')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->get('/internal/v1/drivers?ids=x')->getStatusCode());
    }

    // --- інфраструктура тесту ----------------------------------------------

    private function givenSupplier(string $name, ?StoreAccess $storeAccess = null): Supplier
    {
        return $this->suppliers->create(name: $name, storeAccess: $storeAccess);
    }

    private function get(string $uri): Response
    {
        return $this->httpKernel->handle(
            Request::create($uri, 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            catch: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
