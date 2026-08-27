<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Driver;

use App\Infrastructure\Driver\HttpDriverDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Довідник водіїв поверх partner-service: ПІБ і телефон для картки прибуття.
 */
#[CoversClass(HttpDriverDirectory::class)]
final class HttpDriverDirectoryTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';

    public function testProfilesAreReturnedKeyedByDriverId(): void
    {
        $drivers = $this->directory($this->body([
            ['driverId' => 'du-1', 'fullName' => 'Іваненко Іван', 'phone' => '+380671234567'],
            ['driverId' => 'du-2', 'fullName' => 'Петренко Петро', 'phone' => '+380500000000'],
        ]))->findMany(['du-1', 'du-2']);

        self::assertSame(['du-1', 'du-2'], array_keys($drivers));
        self::assertSame('Іваненко Іван', $drivers['du-1']->fullName);
        self::assertSame('+380500000000', $drivers['du-2']->phone);
        self::assertTrue($drivers['du-1']->active);
    }

    /** Один запит на всю дошку, а не по виклику на бронювання. */
    public function testAllIdsGoInOneBatchedCall(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->body([]));
        });

        (new HttpDriverDirectory($client, self::BASE_URL))->findMany(['du-1', 'du-2', 'du-3']);

        self::assertSame(1, $client->getRequestsCount());
        self::assertStringContainsString('/internal/v1/drivers?ids=du-1%2Cdu-2%2Cdu-3', $captured);
    }

    /** Порожній перелік до сусіда взагалі не їде. */
    public function testEmptyRequestDoesNotTouchNeighbour(): void
    {
        $client = new MockHttpClient([]);

        self::assertSame([], (new HttpDriverDirectory($client, self::BASE_URL))->findMany([]));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testUnknownProfilesAreSimplyAbsent(): void
    {
        $drivers = $this->directory($this->body([
            ['driverId' => 'du-1', 'fullName' => 'Іваненко Іван', 'phone' => null],
        ]))->findMany(['du-1', 'du-zniklyi']);

        self::assertArrayHasKey('du-1', $drivers);
        self::assertArrayNotHasKey('du-zniklyi', $drivers);
        self::assertNull($drivers['du-1']->phone);
    }

    /**
     * Недоступний сусід НЕ ламає дошку: імʼя водія — довідкове збагачення,
     * від якого не залежить жодне доменне правило.
     */
    public function testUnavailableNeighbourDegradesToEmptyResult(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((static function () {
            yield new TransportException('Idle timeout reached.');
        })()));

        self::assertSame([], (new HttpDriverDirectory($client, self::BASE_URL))->findMany(['du-1']));
    }

    public function testServerErrorAlsoDegradesInsteadOfBreakingTheBoard(): void
    {
        $client = new MockHttpClient([new MockResponse('', ['http_code' => 503])]);

        self::assertSame([], (new HttpDriverDirectory($client, self::BASE_URL))->findMany(['du-1']));
    }

    private function directory(string $body): HttpDriverDirectory
    {
        return new HttpDriverDirectory(
            new MockHttpClient(static fn (): MockResponse => new MockResponse($body)),
            self::BASE_URL,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function body(array $items): string
    {
        return json_encode([
            'items' => array_map(
                static fn (array $item): array => array_replace(
                    ['supplierId' => 'sp-1', 'active' => true],
                    $item,
                ),
                $items,
            ),
        ], \JSON_THROW_ON_ERROR);
    }
}
