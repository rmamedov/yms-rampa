<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Store;

use App\Domain\Exception\UpstreamUnavailableException;
use App\Domain\Store\StoreNotFoundException;
use App\Infrastructure\Store\HttpStoreConfigProvider;
use App\Infrastructure\Store\HttpStoreServiceClient;
use App\Tests\Support\StoreSettingsPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Читання конфігурації магазину зі store-service по HTTP (GRID-01, SLOT-04).
 *
 * Мережі тут немає: транспорт підмінений MockHttpClient, тіла відповідей —
 * фікстура реального контракту сусіда.
 */
#[CoversClass(HttpStoreConfigProvider::class)]
#[CoversClass(HttpStoreServiceClient::class)]
final class HttpStoreConfigProviderTest extends TestCase
{
    private const string BASE_URL = 'http://127.0.0.1:8081';

    public function testFullContractIsMappedIntoDomainSettings(): void
    {
        $settings = $this->provider(new MockResponse(StoreSettingsPayload::json()))
            ->settingsFor(StoreSettingsPayload::STORE_ID);

        $config = $settings->config;

        self::assertSame(StoreSettingsPayload::STORE_ID, $config->storeId);
        self::assertTrue($settings->ymsActive);

        // Геометрія сітки.
        self::assertSame(30, $config->slotSizeMinutes);
        self::assertSame(120, $config->leadTimeMinutes);
        self::assertSame(21, $config->bookingHorizonDays);
        self::assertEqualsWithDelta(12.5, $config->maxVehicleWeightTons, 0.001);

        // Вікна прийому: два дні, у понеділка два інтервали.
        self::assertCount(2, $config->receivingWindows);
        $monday = $config->windowForDayOfWeek(1);
        self::assertNotNull($monday);
        self::assertCount(2, $monday->intervals);
        self::assertSame('13:00', $monday->intervals[1]->formatFrom());
        self::assertSame('18:00', $monday->intervals[1]->formatTo());

        // Рампи віддаються всі, вимкнена лишається у списку. Номер потрібен
        // контуру водія: на воротах написано «2», а не «r2» (DRV-21).
        self::assertCount(3, $config->ramps);
        self::assertCount(2, $config->activeRamps());
        self::assertSame('Холодильна', $config->ramps[1]->name);
        self::assertSame(2, $config->ramps[1]->number);
        self::assertSame($config->ramps[1], $config->ramp('r2'));
        self::assertNull($config->ramp('r-невідома'));
        self::assertFalse($config->ramps[2]->active);

        // Обидва типи винятків календаря.
        $holiday = $config->calendarExceptionFor('2026-08-24');
        self::assertNotNull($holiday);
        self::assertTrue($holiday->closed);
        self::assertSame('День Незалежності', $holiday->reason);

        $shortDay = $config->calendarExceptionFor('2026-08-28');
        self::assertNotNull($shortDay);
        self::assertFalse($shortDay->closed);
        self::assertCount(1, $shortDay->intervals);

        // Політики: два поля приходять з магазину, решта — мережеві дефолти 6.11.
        self::assertSame(45, $settings->policy->noShowGraceMinutes);
        self::assertSame(20, $settings->policy->holdMaxMinutes);
        self::assertSame(2, $settings->policy->editDeadlineHours);
        self::assertSame(300, $settings->policy->holdTtlSeconds);
        self::assertSame(50, $settings->policy->maxActiveBookingsPerSupplier);

        // DATA-13: снапшот філії для документа бронювання.
        self::assertSame('00123', $settings->snapshot->externalId);
        self::assertSame('Сільпо на Хрещатику', $settings->snapshot->displayName);
        self::assertSame('вул. Хрещатик, 12', $settings->snapshot->address);

        // DRV-21: координати з того ж блоку — пункт призначення навігатора.
        self::assertNotNull($settings->location);
        self::assertSame(50.49699, $settings->location->latitude);
        self::assertSame(30.36123, $settings->location->longitude);
    }

    /**
     * Координати не вигадуються: філія без них (або з поламаними значеннями)
     * лишається без location, і контур водія веде за текстовою адресою.
     */
    public function testMissingOrBrokenCoordinatesLeaveLocationEmpty(): void
    {
        $withoutCoordinates = $this->provider(new MockResponse(StoreSettingsPayload::json([
            'snapshot' => [
                'externalId' => '00123',
                'displayName' => 'Сільпо на Хрещатику',
                'city' => 'Київ',
                'address' => 'вул. Хрещатик, 12',
            ],
        ])))->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertNull($withoutCoordinates->location);

        $brokenCoordinates = $this->provider(new MockResponse(StoreSettingsPayload::json([
            'snapshot' => [
                'externalId' => '00123',
                'displayName' => 'Сільпо на Хрещатику',
                'city' => 'Київ',
                'address' => 'вул. Хрещатик, 12',
                'latitude' => 'н/д',
                'longitude' => 1000.0,
            ],
        ])))->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertNull($brokenCoordinates->location);
    }

    /** Рампа без номера у відповіді сусіда — це null, а не 0. */
    public function testRampWithoutNumberKeepsNull(): void
    {
        $settings = $this->provider(new MockResponse(StoreSettingsPayload::json([
            'ramps' => [['rampId' => 'r1', 'name' => 'Рампа 1', 'active' => true]],
        ])))->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertNull($settings->config->ramps[0]->number);
        self::assertSame('Рампа 1', $settings->config->ramps[0]->name);
    }

    /**
     * День без інтервалів — це «прийому немає», а не помилка формату.
     *
     * Саме так store-service описує вихідний, і на такій філії побудова сітки
     * падала: постачальник отримував 502 і не бачив слотів узагалі.
     */
    public function testDayWithoutIntervalsIsSkippedInsteadOfFailing(): void
    {
        $settings = $this->provider(new MockResponse(StoreSettingsPayload::json([
            'receivingWindows' => [
                ['dayOfWeek' => 1, 'intervals' => [['from' => '09:00', 'to' => '12:00']]],
                ['dayOfWeek' => 7, 'intervals' => []],
            ],
        ])))->settingsFor(StoreSettingsPayload::STORE_ID);

        $days = array_map(
            static fn ($w): int => $w->dayOfWeek,
            $settings->config->receivingWindows,
        );

        self::assertSame([1], $days, 'порожня неділя не має ані ламати мапінг, ані давати слоти');
    }

    /**
     * Запит іде на службовий маршрут внутрішнього шлюзу, БЕЗ заголовків
     * ідентичності (їх там ніхто не перевіряє) і з таймаутом у кілька секунд.
     */
    public function testRequestGoesToInternalSettingsRoute(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(StoreSettingsPayload::json());
        });

        (new HttpStoreConfigProvider(new HttpStoreServiceClient($client, self::BASE_URL)))
            ->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertSame('GET', $captured['method']);
        self::assertSame(
            self::BASE_URL.'/internal/v1/stores/'.StoreSettingsPayload::STORE_ID.'/settings',
            $captured['url'],
        );
        self::assertStringNotContainsString('/api/', $captured['url']);
        self::assertLessThanOrEqual(3.0, $captured['options']['timeout']);
        self::assertLessThanOrEqual(3.0, $captured['options']['max_duration']);

        foreach ($captured['options']['headers'] as $header) {
            self::assertStringNotContainsString('X-Yms', $header);
        }
    }

    /** Ідентифікатор екранується: він приходить ззовні і не має ламати маршрут. */
    public function testStoreIdIsEscapedInPath(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse(StoreSettingsPayload::json());
        });

        (new HttpStoreConfigProvider(new HttpStoreServiceClient($client, self::BASE_URL)))
            ->settingsFor('../admin/секрет');

        self::assertSame(self::BASE_URL.'/internal/v1/stores/..%2Fadmin%2F%D1%81%D0%B5%D0%BA%D1%80%D0%B5%D1%82/settings', $captured);
    }

    public function testUnknownStoreBecomesStoreNotFound(): void
    {
        $response = new MockResponse(StoreSettingsPayload::notFoundProblem(), ['http_code' => 404]);

        $this->expectException(StoreNotFoundException::class);
        $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
    }

    /**
     * STORE_NOT_CONFIGURED (магазин на паузі або без чинної конфігурації) для
     * бронювання означає те саме, що й відсутня філія: 404 без деталей.
     */
    public function testNotConfiguredStoreBecomesStoreNotFound(): void
    {
        $response = new MockResponse(
            StoreSettingsPayload::notFoundProblem('STORE_NOT_CONFIGURED'),
            ['http_code' => 404],
        );

        try {
            $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася StoreNotFoundException.');
        } catch (StoreNotFoundException $error) {
            self::assertSame('STORE_NOT_FOUND', $error->errorCode());
            self::assertSame(404, $error->httpStatus());
        }
    }

    /** Магазин на паузі: для контуру постачальника його не існує (GRID-01, крок 2). */
    public function testPausedStoreIsNotYmsActive(): void
    {
        $settings = $this->provider(new MockResponse(StoreSettingsPayload::json(['ymsStatus' => 'paused'])))
            ->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertFalse($settings->ymsActive);
    }

    /** Прихована від постачальників філія теж не бронюється постачальником. */
    public function testStoreHiddenFromSuppliersIsNotYmsActive(): void
    {
        $settings = $this->provider(new MockResponse(StoreSettingsPayload::json(['visibleToSuppliers' => false])))
            ->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertFalse($settings->ymsActive);
    }

    /** Таймаут або обрив: доменна помилка 503, а не 500 зі стектрейсом. */
    public function testTimeoutBecomesUpstreamUnavailable(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((static function () {
            yield new TransportException('Idle timeout reached for "http://127.0.0.1:8081".');
        })()));

        try {
            (new HttpStoreConfigProvider(new HttpStoreServiceClient($client, self::BASE_URL)))
                ->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertSame('UPSTREAM_UNAVAILABLE', $error->errorCode());
            self::assertSame('store-service', $error->service);
            self::assertSame(['service' => 'store-service'], $error->problemExtensions());
            self::assertStringContainsString('Сервіс налаштувань філій тимчасово недоступний', $error->getMessage());
        }
    }

    public function testServerErrorBecomesUpstreamUnavailable(): void
    {
        $response = new MockResponse('', ['http_code' => 500]);

        try {
            $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(503, $error->httpStatus());
            self::assertStringContainsString('HTTP 500', $error->getMessage());
        }
    }

    /** Відповідь є, але це не JSON: повторювати немає сенсу — 502. */
    public function testInvalidJsonBecomesBadResponse(): void
    {
        $response = new MockResponse('<html>502 Bad Gateway</html>');

        try {
            $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertSame('UPSTREAM_BAD_RESPONSE', $error->errorCode());
            self::assertStringContainsString('некоректний JSON', $error->getMessage());
        }
    }

    /** JSON валідний, але доменні інваріанти не збираються — теж 502, не 500. */
    public function testBrokenContractBecomesBadResponse(): void
    {
        $response = new MockResponse(StoreSettingsPayload::json(['slotSizeMinutes' => 45]));

        try {
            $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertStringContainsString('slotSizeMinutes', $error->getMessage());
        }
    }

    /**
     * Відсутні поля не мають давати PHP-warning «Undefined array key»
     * (phpunit валить тест на будь-якому warning) — лише зрозумілий 502.
     */
    public function testIncompletePayloadBecomesBadResponse(): void
    {
        $response = new MockResponse(StoreSettingsPayload::json(['ramps' => [['number' => 1, 'active' => true]]]));

        try {
            $this->provider($response)->settingsFor(StoreSettingsPayload::STORE_ID);
            self::fail('Очікувалася UpstreamUnavailableException.');
        } catch (UpstreamUnavailableException $error) {
            self::assertSame(502, $error->httpStatus());
            self::assertStringContainsString('rampId', $error->getMessage());
        }
    }

    /**
     * Повторне звернення за тим самим магазином у межах запиту не смикає
     * сусіда вдруге.
     */
    public function testRepeatedLookupsHitNeighbourOnce(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(StoreSettingsPayload::json()));
        $provider = new HttpStoreConfigProvider(new HttpStoreServiceClient($client, self::BASE_URL));

        $first = $provider->settingsFor(StoreSettingsPayload::STORE_ID);
        $second = $provider->settingsFor(StoreSettingsPayload::STORE_ID);

        self::assertSame(1, $client->getRequestsCount());
        self::assertSame($first->storeId(), $second->storeId());
    }

    /** Кешується і відповідь «магазину немає»: другого виклику теж не буде. */
    public function testMissingStoreIsCachedToo(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            StoreSettingsPayload::notFoundProblem(),
            ['http_code' => 404],
        ));
        $provider = new HttpStoreConfigProvider(new HttpStoreServiceClient($client, self::BASE_URL));

        foreach (range(1, 2) as $ignored) {
            try {
                $provider->settingsFor(StoreSettingsPayload::STORE_ID);
                self::fail('Очікувалася StoreNotFoundException.');
            } catch (StoreNotFoundException) {
                // очікувано
            }
        }

        self::assertSame(1, $client->getRequestsCount());
    }

    private function provider(ResponseInterface $response): HttpStoreConfigProvider
    {
        return new HttpStoreConfigProvider(
            new HttpStoreServiceClient(new MockHttpClient($response), self::BASE_URL)
        );
    }
}
