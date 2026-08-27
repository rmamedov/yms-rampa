<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Store;

use App\Domain\Exception\UpstreamUnavailableException;
use App\Infrastructure\Store\HttpSlotOverlayProvider;
use App\Infrastructure\Store\HttpStoreConfigProvider;
use App\Infrastructure\Store\HttpStoreServiceClient;
use App\Tests\Support\StoreSettingsPayload;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Накладання сітки (резерви і блокування) з реального store-service (DATA-11).
 * Мережі немає: транспорт підмінений MockHttpClient.
 */
#[CoversClass(HttpSlotOverlayProvider::class)]
final class HttpSlotOverlayProviderTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';

    public function testReservedRulesAreMappedWithBothPeriodKinds(): void
    {
        $rules = $this->provider(StoreSettingsPayload::json())
            ->reservedRulesFor(StoreSettingsPayload::STORE_ID);

        self::assertCount(2, $rules);

        // Щотижневе правило: вівторок, рампа r1, 09:00, без кінця дії.
        [$weekly, $once] = $rules;
        self::assertSame('sp-1', $weekly->supplierId);
        self::assertSame(2, $weekly->dayOfWeek);
        self::assertNull($weekly->date);
        self::assertTrue($weekly->matches('2026-09-01', 2, '09:00', 'r1'));
        self::assertFalse($weekly->matches('2026-09-01', 2, '09:00', 'r2'));
        // Дата до validFrom правилом не накривається.
        self::assertFalse($weekly->matches('2026-07-28', 2, '09:00', 'r1'));

        // Разовий резерв: конкретна дата, поза нею не діє.
        self::assertNull($once->dayOfWeek);
        self::assertSame('2026-09-03', $once->date);
        self::assertTrue($once->matches('2026-09-03', 4, '10:30', 'r2'));
        self::assertFalse($once->matches('2026-09-10', 4, '10:30', 'r2'));
    }

    /**
     * Одне блокування store-service може накривати кілька рамп, доменний
     * SlotBlock — рівно одну; блокування «на всі рампи» стає rampId = null.
     */
    public function testBlocksAreExpandedPerRamp(): void
    {
        $blocks = $this->provider(StoreSettingsPayload::json())->blocksFor(
            StoreSettingsPayload::STORE_ID,
            new DateTimeImmutable('2026-08-01T00:00:00Z'),
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
        );

        // 1 (одна рампа) + 2 (дві рампи) + 1 (всі рампи) = 4.
        self::assertCount(4, $blocks);
        self::assertSame(['r1', 'r1', 'r2', null], array_map(static fn ($block) => $block->rampId, $blocks));
        self::assertSame('Ремонт воріт', $blocks[0]->reason);
        self::assertSame('2026-08-28T05:00:00+00:00', $blocks[0]->from->format(\DATE_ATOM));
        self::assertSame('2026-08-28T07:00:00+00:00', $blocks[0]->to->format(\DATE_ATOM));

        foreach ($blocks as $block) {
            self::assertSame(StoreSettingsPayload::STORE_ID, $block->storeId);
        }
    }

    /** Сусід віддає блокування на весь горизонт — добу відбирає вже клієнт. */
    public function testBlocksOutsideRequestedRangeAreFilteredOut(): void
    {
        $blocks = $this->provider(StoreSettingsPayload::json())->blocksFor(
            StoreSettingsPayload::STORE_ID,
            new DateTimeImmutable('2026-08-27T21:00:00Z'),
            new DateTimeImmutable('2026-08-28T21:00:00Z'),
        );

        self::assertCount(1, $blocks);
        self::assertSame('Ремонт воріт', $blocks[0]->reason);
    }

    /**
     * Побудова сітки читає тіло тричі (налаштування, блокування, резерви),
     * але обидва адаптери сидять на одному клієнті — виклик до сусіда один.
     */
    public function testConfigAndOverlaysShareOneNetworkCall(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(StoreSettingsPayload::json()));
        $storeClient = new HttpStoreServiceClient($client, self::BASE_URL);

        $settings = (new HttpStoreConfigProvider($storeClient))->settingsFor(StoreSettingsPayload::STORE_ID);
        $overlays = new HttpSlotOverlayProvider($storeClient);

        $overlays->blocksFor(
            $settings->storeId(),
            new DateTimeImmutable('2026-08-28T00:00:00Z'),
            new DateTimeImmutable('2026-08-29T00:00:00Z'),
        );
        $overlays->reservedRulesFor($settings->storeId());

        self::assertSame(1, $client->getRequestsCount());
    }

    /** Магазину немає — накладати нема на що; про 404 скаже StoreConfigProvider. */
    public function testMissingStoreYieldsNoOverlays(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            StoreSettingsPayload::notFoundProblem(),
            ['http_code' => 404],
        ));
        $overlays = new HttpSlotOverlayProvider(new HttpStoreServiceClient($client, self::BASE_URL));

        self::assertSame([], $overlays->reservedRulesFor(StoreSettingsPayload::STORE_ID));
        self::assertSame([], $overlays->blocksFor(
            StoreSettingsPayload::STORE_ID,
            new DateTimeImmutable('2026-08-28T00:00:00Z'),
            new DateTimeImmutable('2026-08-29T00:00:00Z'),
        ));
    }

    /** Блокування без межі часу — поламаний контракт, а не «блокування зараз». */
    public function testBlockWithoutBoundaryBecomesBadResponse(): void
    {
        $provider = $this->provider(StoreSettingsPayload::json([
            'slotBlocks' => [[
                'rampIds' => ['r1'],
                'coversAllRamps' => false,
                'blockFrom' => null,
                'blockTo' => '2026-08-28T07:00:00+00:00',
                'reason' => 'Ремонт',
            ]],
        ]));

        try {
            $provider->blocksFor(
                StoreSettingsPayload::STORE_ID,
                new DateTimeImmutable('2026-08-28T00:00:00Z'),
                new DateTimeImmutable('2026-08-29T00:00:00Z'),
            );
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertSame('UPSTREAM_BAD_RESPONSE', $error->errorCode());
        }
    }

    /** Правило резерву без дня тижня і без дати доменом не приймається. */
    public function testBrokenReservedRuleBecomesBadResponse(): void
    {
        $provider = $this->provider(StoreSettingsPayload::json([
            'reservedSlotRules' => [[
                'supplierId' => 'sp-1',
                'rampId' => 'r1',
                'slotStartTime' => '09:00',
                'dayOfWeek' => null,
                'date' => null,
                'validFrom' => null,
                'validTo' => null,
                'active' => true,
            ]],
        ]));

        try {
            $provider->reservedRulesFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertSame('store-service', $error->service);
        }
    }

    /** Правило без обовʼязкових полів — 502 і без PHP-warning про ключі. */
    public function testIncompleteReservedRuleBecomesBadResponse(): void
    {
        $provider = $this->provider(StoreSettingsPayload::json([
            'reservedSlotRules' => [['dayOfWeek' => 2]],
        ]));

        try {
            $provider->reservedRulesFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertStringContainsString('обовʼязкові', $error->getMessage());
        }
    }

    public function testInvalidJsonBecomesBadResponse(): void
    {
        $this->expectException(UpstreamUnavailableException::class);
        $this->provider('не json')->reservedRulesFor(StoreSettingsPayload::STORE_ID);
    }

    private function provider(string $body): HttpSlotOverlayProvider
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($body));

        return new HttpSlotOverlayProvider(new HttpStoreServiceClient($client, self::BASE_URL));
    }
}
