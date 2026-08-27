<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Service\SupplierService;
use App\Domain\Supplier\StoreAccess;
use App\Domain\Supplier\Supplier;
use App\Domain\Supplier\SupplierAccessSnapshot;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Службовий контракт для booking-service (BOOK-02):
 *   GET /internal/v1/suppliers/{supplierId}
 *   GET /internal/v1/suppliers/{supplierId}/store-access/{storeId}
 *
 * Запити подаються прямо в ядро, дані створюються доменним сервісом на
 * InMemory-сховищі — ані вебсервера, ані MongoDB, ані мережі тест не потребує.
 */
final class InternalSupplierApiTest extends KernelTestCase
{
    private KernelInterface $httpKernel;
    private SupplierService $suppliers;

    protected function setUp(): void
    {
        $this->httpKernel = self::bootKernel();

        /** @var SupplierService $suppliers */
        $suppliers = self::getContainer()->get(SupplierService::class);
        $this->suppliers = $suppliers;
    }

    /**
     * Активний постачальник із доступом до всіх магазинів: allStores=true,
     * перелік дозволених порожній, бронювати можна в будь-якій філії.
     */
    public function testActiveSupplierWithAllStoresAccess(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');

        $payload = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id()));

        self::assertSame($supplier->id(), $payload['supplierId']);
        self::assertSame('ТОВ «Логістик Плюс»', $payload['name']);
        self::assertSame('active', $payload['status']);
        self::assertTrue($payload['allStores']);
        self::assertSame([], $payload['allowedStoreIds']);

        $access = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id().'/store-access/S-099'));

        self::assertSame('S-099', $access['storeId']);
        self::assertTrue($access['allowed']);
        self::assertNull($access['reason']);
    }

    /**
     * Активний постачальник із whitelist: дозволена філія — так,
     * будь-яка інша — ні, з машинною причиною SUPPLIER_STORE_NOT_ALLOWED.
     */
    public function testActiveSupplierWithWhitelist(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Схід Транс»', StoreAccess::whitelist(['S-02', 'S-01']));

        $payload = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id()));

        self::assertSame('active', $payload['status']);
        self::assertFalse($payload['allStores']);
        self::assertSame(['S-01', 'S-02'], $payload['allowedStoreIds']);

        $allowed = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id().'/store-access/S-01'));
        $denied = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id().'/store-access/S-07'));

        self::assertTrue($allowed['allowed']);
        self::assertNull($allowed['reason']);

        self::assertFalse($denied['allowed']);
        self::assertSame(SupplierAccessSnapshot::REASON_STORE_NOT_ALLOWED, $denied['reason']);
        // Відмова — це 200 із рішенням, а не помилка: питання поставлене коректно.
        self::assertSame(Response::HTTP_OK, $this->get(
            '/internal/v1/suppliers/'.$supplier->id().'/store-access/S-07',
        )->getStatusCode());
    }

    /**
     * SUP-02: призупинений постачальник не має доступу навіть до магазину
     * зі свого whitelist, і причиною є саме статус.
     */
    public function testSuspendedSupplierHasNoAccessEvenToWhitelistedStore(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Захід Логістика»', StoreAccess::whitelist(['S-01']));
        $this->suppliers->suspend($supplier->id(), 'Заборгованість');

        $payload = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id()));

        self::assertSame('suspended', $payload['status']);
        self::assertSame(['S-01'], $payload['allowedStoreIds']);

        $access = self::decode($this->get('/internal/v1/suppliers/'.$supplier->id().'/store-access/S-01'));

        self::assertFalse($access['allowed']);
        self::assertSame(SupplierAccessSnapshot::REASON_SUSPENDED, $access['reason']);
    }

    /**
     * Неіснуючий постачальник — 404 з кодом SUPPLIER_NOT_FOUND у форматі
     * RFC 7807 на обох маршрутах.
     */
    public function testUnknownSupplierReturns404ProblemJson(): void
    {
        $uris = [
            '/internal/v1/suppliers/sp-unknown',
            '/internal/v1/suppliers/sp-unknown/store-access/S-01',
        ];

        foreach ($uris as $uri) {
            $response = $this->get($uri);

            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $uri);
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'), $uri);

            $payload = self::decode($response);
            self::assertSame('SUPPLIER_NOT_FOUND', $payload['code'], $uri);
            self::assertSame(404, $payload['status'], $uri);
            self::assertArrayHasKey('requestId', $payload, $uri);
        }
    }

    /**
     * Службові маршрути не проходять через auth_request і заголовків
     * ідентичності не мають: запит без них має обслуговуватися, тоді як
     * той самий безіменний запит до /api/ — ні.
     */
    public function testInternalRoutesDoNotRequireIdentityHeaders(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');

        self::assertSame(
            Response::HTTP_OK,
            $this->get('/internal/v1/suppliers/'.$supplier->id())->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->get('/api/admin/v1/suppliers/'.$supplier->id())->getStatusCode(),
        );
    }

    // --- інфраструктура тесту ---------------------------------------------

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
