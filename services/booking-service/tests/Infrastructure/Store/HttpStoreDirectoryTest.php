<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Store;

use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\Store\HttpStoreDirectory;
use App\Infrastructure\Store\HttpStoreServiceClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Перелік магазинів зі store-service (GET /internal/v1/stores).
 * Мережі немає: транспорт підмінений MockHttpClient.
 */
#[CoversClass(HttpStoreDirectory::class)]
#[CoversClass(HttpStoreServiceClient::class)]
final class HttpStoreDirectoryTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';

    public function testSnapshotIsMappedIntoDomainBrief(): void
    {
        $stores = $this->directory([$this->page([$this->item('s-1', '1998', 'Сільпо Хрещатик')])])
            ->listStores(null);

        self::assertCount(1, $stores);
        self::assertSame('s-1', $stores[0]->storeId);
        self::assertSame('1998', $stores[0]->externalId);
        self::assertSame('Сільпо Хрещатик', $stores[0]->displayName);
        self::assertSame('Київ', $stores[0]->city);
        self::assertSame('вул. Хрещатик, 12', $stores[0]->address);
        self::assertSame('active', $stores[0]->ymsStatus);
    }

    /**
     * ДЕФЕКТ «показано лише першу сторінку»: сусід пагінує, а домену потрібен
     * повний перелік — клієнт зобовʼязаний дійти до останньої сторінки.
     */
    public function testAllPagesAreFetched(): void
    {
        $client = new MockHttpClient([
            new MockResponse($this->page([$this->item('s-1'), $this->item('s-2')], page: 1, pages: 3)),
            new MockResponse($this->page([$this->item('s-3'), $this->item('s-4')], page: 2, pages: 3)),
            new MockResponse($this->page([$this->item('s-5')], page: 3, pages: 3)),
        ]);

        $stores = (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))->listStores(null);

        self::assertSame(['s-1', 's-2', 's-3', 's-4', 's-5'], array_column(
            array_map(static fn ($s) => $s->toArray(), $stores),
            'storeId',
        ));
        self::assertSame(3, $client->getRequestsCount());
    }

    public function testSinglePageStopsAfterOneCall(): void
    {
        $client = new MockHttpClient([new MockResponse($this->page([$this->item('s-1')]))]);

        (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))->listStores(null);

        self::assertSame(1, $client->getRequestsCount());
    }

    /** Скоуп їде предикатом запиту, а не фільтрується вже отриманою сторінкою. */
    public function testScopeIsPassedToNeighbourAsQueryParameter(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->page([$this->item('s-1')]));
        });

        (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))
            ->listStores(['s-1', 's-2']);

        self::assertStringContainsString('/internal/v1/stores?', $captured);
        self::assertStringContainsString('storeIds=s-1%2Cs-2', $captured);
        self::assertStringNotContainsString('/api/', $captured);
    }

    /** Без звуження параметр не надсилається взагалі — це «вся мережа». */
    public function testNetworkWideRequestOmitsScopeParameter(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->page([]));
        });

        (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))->listStores(null);

        self::assertStringNotContainsString('storeIds', $captured);
    }

    /** 404 службового маршруту — порожній перелік, а не 500 на весь екран. */
    public function testMissingRouteYieldsEmptyList(): void
    {
        $client = new MockHttpClient([new MockResponse('{"code":"ROUTE_NOT_FOUND"}', ['http_code' => 404])]);

        self::assertSame([], (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))
            ->listStores(null));
    }

    /** Недоступний сусід — доменний 503 з кодом, а не сирий виняток транспорту. */
    public function testServerErrorBecomesUpstreamUnavailable(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 500])]);

        $this->expectException(UpstreamUnavailableException::class);
        (new HttpStoreDirectory(new HttpStoreServiceClient($client, self::BASE_URL)))->listStores(null);
    }

    /**
     * @param list<MockResponse|string> $pages
     */
    private function directory(array $pages): HttpStoreDirectory
    {
        $responses = array_map(
            static fn ($page): MockResponse => $page instanceof MockResponse ? $page : new MockResponse($page),
            $pages,
        );

        return new HttpStoreDirectory(new HttpStoreServiceClient(new MockHttpClient($responses), self::BASE_URL));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $storeId, string $externalId = '1998', string $displayName = 'Сільпо'): array
    {
        return [
            'storeId' => $storeId,
            'ymsStatus' => 'active',
            'snapshot' => [
                'externalId' => $externalId,
                'displayName' => $displayName,
                'city' => 'Київ',
                'address' => 'вул. Хрещатик, 12',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function page(array $items, int $page = 1, int $pages = 1): string
    {
        return json_encode([
            'items' => $items,
            'total' => \count($items) * $pages,
            'page' => $page,
            'perPage' => 100,
            'pages' => $pages,
        ], \JSON_THROW_ON_ERROR);
    }
}
