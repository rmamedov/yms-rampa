<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Supplier;

use App\Domain\Booking\Exception\SupplierNotAllowedException;
use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\Supplier\HttpSupplierDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Довідник постачальників поверх partner-service (BOOK-02).
 * Мережі немає: транспорт підмінений MockHttpClient.
 */
#[CoversClass(HttpSupplierDirectory::class)]
final class HttpSupplierDirectoryTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';
    private const string SUPPLIER_ID = 'a1b2c3d4-0000-4000-8000-000000000001';
    private const string STORE_ID = '9f3b1c2e-0f7a-4d5b-8c6e-1a2b3c4d5e6f';

    public function testFindReturnsSupplierWithAccessToAllStores(): void
    {
        $supplier = $this->directory($this->supplierBody())->find(self::SUPPLIER_ID);

        self::assertNotNull($supplier);
        self::assertSame(self::SUPPLIER_ID, $supplier->supplierId);
        self::assertSame('ТОВ «Молочна ріка»', $supplier->name);
        self::assertTrue($supplier->active);
        // allStores=true partner-service віддає з порожнім переліком — саме так
        // SupplierInfo кодує доступ до всіх філій.
        self::assertSame([], $supplier->allowedStoreIds);
        self::assertTrue($supplier->hasAccessTo('будь-яка-філія'));
    }

    public function testFindKeepsWhitelistWhenSupplierIsLimited(): void
    {
        $supplier = $this->directory($this->supplierBody([
            'allStores' => false,
            'allowedStoreIds' => [self::STORE_ID, 'store-2'],
        ]))->find(self::SUPPLIER_ID);

        self::assertNotNull($supplier);
        self::assertSame([self::STORE_ID, 'store-2'], $supplier->allowedStoreIds);
    }

    public function testSuspendedSupplierIsReportedAsInactive(): void
    {
        $supplier = $this->directory($this->supplierBody(['status' => 'suspended']))->find(self::SUPPLIER_ID);

        self::assertNotNull($supplier);
        self::assertFalse($supplier->active);
    }

    public function testUnknownSupplierIsNull(): void
    {
        $directory = $this->directory($this->notFoundProblem(), 404);

        self::assertNull($directory->find(self::SUPPLIER_ID));
    }

    public function testAccessCheckAsksNeighbourForVerdict(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse($this->accessBody());
        });

        $supplier = (new HttpSupplierDirectory($client, self::BASE_URL))
            ->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);

        self::assertSame('ТОВ «Молочна ріка»', $supplier->name);
        self::assertSame('GET', $captured['method']);
        self::assertSame(
            self::BASE_URL.'/internal/v1/suppliers/'.self::SUPPLIER_ID.'/store-access/'.self::STORE_ID,
            $captured['url'],
        );
        self::assertStringNotContainsString('/api/', $captured['url']);
        self::assertLessThanOrEqual(3.0, $captured['options']['timeout']);

        foreach ($captured['options']['headers'] as $header) {
            self::assertStringNotContainsString('X-Yms', $header);
        }
    }

    /** SUP-02: призупинений постачальник не бронює навіть у «своїй» філії. */
    public function testSuspendedSupplierIsRejected(): void
    {
        $directory = $this->directory($this->accessBody([
            'status' => 'suspended',
            'allowed' => false,
            'reason' => 'SUPPLIER_SUSPENDED',
        ]));

        try {
            $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
            self::fail('Очікувалася SupplierNotAllowedException.');
        } catch (SupplierNotAllowedException $error) {
            self::assertSame('SUPPLIER_NOT_ALLOWED', $error->errorCode());
            self::assertSame(403, $error->httpStatus());
            self::assertSame(self::SUPPLIER_ID, $error->supplierId);
            self::assertSame(self::STORE_ID, $error->storeId);
            self::assertStringContainsString('неактивний', $error->getMessage());
        }
    }

    public function testStoreOutsideWhitelistIsRejected(): void
    {
        $directory = $this->directory($this->accessBody([
            'allStores' => false,
            'allowedStoreIds' => ['store-2'],
            'allowed' => false,
            'reason' => 'SUPPLIER_STORE_NOT_ALLOWED',
        ]));

        try {
            $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
            self::fail('Очікувалася SupplierNotAllowedException.');
        } catch (SupplierNotAllowedException $error) {
            self::assertSame('SUPPLIER_NOT_ALLOWED', $error->errorCode());
            self::assertStringContainsString('не має доступу до цієї філії', $error->getMessage());
        }
    }

    public function testUnknownSupplierIsRejected(): void
    {
        $directory = $this->directory($this->notFoundProblem(), 404);

        try {
            $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
            self::fail('Очікувалася SupplierNotAllowedException.');
        } catch (SupplierNotAllowedException $error) {
            self::assertStringContainsString('Постачальника не знайдено', $error->getMessage());
        }
    }

    /**
     * Вердикт беремо з поля `allowed`, а не з локального перебору whitelist:
     * порожній перелік при allStores=false у partner-service означає «жодної
     * філії», а SupplierInfo читав би його як «усі».
     */
    public function testEmptyWhitelistWithoutAllStoresIsStillRejected(): void
    {
        $directory = $this->directory($this->accessBody([
            'allStores' => false,
            'allowedStoreIds' => [],
            'allowed' => false,
            'reason' => 'SUPPLIER_STORE_NOT_ALLOWED',
        ]));

        $this->expectException(SupplierNotAllowedException::class);
        $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
    }

    /** Недоступний сусід — 503 з кодом, а не 500 зі стектрейсом. */
    public function testTimeoutBecomesUpstreamUnavailable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((static function () {
            yield new TransportException('Idle timeout reached for "http://127.0.0.1:8081".');
        })()));

        try {
            (new HttpSupplierDirectory($client, self::BASE_URL))
                ->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertSame('UPSTREAM_UNAVAILABLE', $error->errorCode());
            self::assertSame('partner-service', $error->service);
            self::assertStringContainsString('Сервіс постачальників тимчасово недоступний', $error->getMessage());
        }
    }

    public function testServerErrorBecomesUpstreamUnavailable(): void
    {
        $directory = $this->directory('', 503);

        try {
            $directory->find(self::SUPPLIER_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringContainsString('HTTP 503', $error->getMessage());
        }
    }

    public function testInvalidJsonBecomesBadResponse(): void
    {
        $directory = $this->directory('{"supplierId": ');

        try {
            $directory->find(self::SUPPLIER_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertSame('UPSTREAM_BAD_RESPONSE', $error->errorCode());
            self::assertSame('partner-service', $error->service);
        }
    }

    /**
     * Відповідь store-access є надмножиною знімка постачальника, тому
     * наступний find() у межах запиту сусіда вже не смикає.
     */
    public function testAccessCheckPrimesSupplierCache(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse($this->accessBody()));
        $directory = new HttpSupplierDirectory($client, self::BASE_URL);

        $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
        $found = $directory->find(self::SUPPLIER_ID);

        self::assertNotNull($found);
        self::assertSame('ТОВ «Молочна ріка»', $found->name);
        self::assertSame(1, $client->getRequestsCount());
    }

    /** Повторна перевірка тієї самої пари теж обслуговується з памʼяті. */
    public function testRepeatedAccessCheckHitsNeighbourOnce(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse($this->accessBody()));
        $directory = new HttpSupplierDirectory($client, self::BASE_URL);

        $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);
        $directory->assertMayBookAt(self::SUPPLIER_ID, self::STORE_ID);

        self::assertSame(1, $client->getRequestsCount());
    }

    // --- Довідник для форми позапланового прибуття -------------------------

    /**
     * ДЕФЕКТ «показано лише першу сторінку»: приймальник не знаходив
     * контрагента і заводив прибуття «поза системою». Клієнт зобовʼязаний
     * пройти всі сторінки сусіда.
     */
    public function testDirectoryFetchesEveryPage(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->listBody([['supplierId' => 'sp-1', 'name' => 'А']], hasMore: true)),
            new MockResponse($this->listBody([['supplierId' => 'sp-2', 'name' => 'Б']], hasMore: true)),
            new MockResponse($this->listBody([['supplierId' => 'sp-3', 'name' => 'В']], hasMore: false)),
        ]);

        $suppliers = (new HttpSupplierDirectory($client, self::BASE_URL))->listForStore(self::STORE_ID);

        self::assertSame(['sp-1', 'sp-2', 'sp-3'], array_map(
            static fn ($supplier): string => $supplier->supplierId,
            $suppliers,
        ));
        self::assertSame(3, $client->getRequestsCount());
    }

    /**
     * Порожня сторінка НЕ означає кінець: partner-service фільтрує сторінку за
     * доступом до філії вже після вибірки, тож гортати треба за `hasMore`.
     */
    public function testEmptyPageDoesNotStopPaging(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->listBody([], hasMore: true)),
            new MockResponse($this->listBody([['supplierId' => 'sp-9', 'name' => 'Я']], hasMore: false)),
        ]);

        $suppliers = (new HttpSupplierDirectory($client, self::BASE_URL))->listForStore(self::STORE_ID);

        self::assertCount(1, $suppliers);
        self::assertSame('sp-9', $suppliers[0]->supplierId);
        self::assertSame(2, $client->getRequestsCount());
    }

    /**
     * Довідник для walk-in НЕ звужується до філії: прибуття тому й позапланове,
     * що постачальник міг не мати доступу саме до цього магазину. Фільтр за
     * storeId сховав би від приймальника справжнього контрагента, і той завів
     * би машину як «поза системою».
     */
    public function testDirectoryDoesNotNarrowSuppliersToTheStore(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->listBody([], hasMore: false));
        });

        (new HttpSupplierDirectory($client, self::BASE_URL))->listForStore(self::STORE_ID);

        self::assertStringContainsString('/internal/v1/suppliers?', $captured);
        self::assertStringNotContainsString('storeId=', $captured);
        // Призупинені теж потрібні — вони можуть приїхати без попередження.
        self::assertStringContainsString('status=any', $captured);
        self::assertStringContainsString('limit=100', $captured);
        self::assertStringNotContainsString('/api/', $captured);
    }

    /** Перелік упорядкований за назвою — у списку вибору порядок має сенс. */
    public function testDirectoryIsSortedByName(): void
    {
        $client = new MockHttpClient([new MockResponse($this->listBody([
            ['supplierId' => 'sp-2', 'name' => 'Bravo'],
            ['supplierId' => 'sp-1', 'name' => 'Alpha'],
        ], hasMore: false))]);

        $suppliers = (new HttpSupplierDirectory($client, self::BASE_URL))->listForStore(self::STORE_ID);

        self::assertSame(['Alpha', 'Bravo'], array_map(
            static fn ($supplier): string => $supplier->name,
            $suppliers,
        ));
    }

    /** 404 службового маршруту — порожній довідник, а не падіння форми. */
    public function testMissingListRouteYieldsEmptyDirectory(): void
    {
        $directory = $this->directory($this->notFoundProblem(), 404);

        self::assertSame([], $directory->listForStore(self::STORE_ID));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function listBody(array $items, bool $hasMore = false): string
    {
        return json_encode([
            'items' => array_map(static fn (array $item): array => array_replace([
                'status' => 'active',
                'allStores' => true,
                'allowedStoreIds' => [],
            ], $item), $items),
            'total' => \count($items),
            'limit' => 100,
            'offset' => 0,
            'hasMore' => $hasMore,
        ], \JSON_THROW_ON_ERROR);
    }

    private function directory(string $body, int $status = 200): HttpSupplierDirectory
    {
        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse($body, ['http_code' => $status])
        );

        return new HttpSupplierDirectory($client, self::BASE_URL);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function supplierBody(array $overrides = []): string
    {
        return json_encode(array_replace([
            'supplierId' => self::SUPPLIER_ID,
            'name' => 'ТОВ «Молочна ріка»',
            'status' => 'active',
            'allStores' => true,
            'allowedStoreIds' => [],
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function accessBody(array $overrides = []): string
    {
        /** @var array<string, mixed> $supplier */
        $supplier = json_decode($this->supplierBody(), true, 8, \JSON_THROW_ON_ERROR);

        return json_encode(array_replace($supplier, [
            'storeId' => self::STORE_ID,
            'allowed' => true,
            'reason' => null,
        ], $overrides), \JSON_THROW_ON_ERROR);
    }

    private function notFoundProblem(): string
    {
        return json_encode([
            'type' => 'about:blank',
            'title' => 'Не знайдено',
            'status' => 404,
            'detail' => 'Постачальника не знайдено',
            'code' => 'SUPPLIER_NOT_FOUND',
            'requestId' => 'req-partner-1',
        ], \JSON_THROW_ON_ERROR);
    }
}
