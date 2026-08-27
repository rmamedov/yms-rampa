<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Перевірка HTTP-контуру: схема URL /api/{admin|store|supplier|driver}/v1/...,
 * формат помилок RFC 7807 і наскрізні правила довідників.
 *
 * Запити подаються прямо в ядро (без browser-kit), тому тест не потребує
 * ані вебсервера, ані MongoDB.
 */
final class ApiEndpointsTest extends KernelTestCase
{
    private const SUPPLIER_HEADER = 'HTTP_X-Supplier-Id';

    private KernelInterface $httpKernel;

    protected function setUp(): void
    {
        $this->httpKernel = self::bootKernel();
    }

    public function testSupplierIsCreatedThroughAdminApi(): void
    {
        $response = $this->json('POST', '/api/admin/v1/suppliers', [
            'name' => 'ТОВ «Логістик Плюс»',
            'edrpou' => '12345678',
            'contacts' => [
                ['name' => 'Олена Мельник', 'phone' => '050 123 45 67', 'email' => 'olena@example.com'],
            ],
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertSame('ТОВ «Логістик Плюс»', $payload['name']);
        self::assertSame('active', $payload['status']);
        self::assertTrue($payload['storeAccess']['allStores']);
        self::assertSame('+380501234567', $payload['contacts'][0]['phone']);
    }

    /**
     * SUP-01 + формат помилок: дублікат назви — 409 у problem+json.
     */
    public function testDuplicateSupplierNameReturnsProblemJson(): void
    {
        $this->json('POST', '/api/admin/v1/suppliers', ['name' => 'ТОВ «Логістик Плюс»']);
        $response = $this->json('POST', '/api/admin/v1/suppliers', ['name' => 'ТОВ «Логістик Плюс»']);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = self::decode($response);
        self::assertSame('about:blank', $payload['type']);
        self::assertSame(409, $payload['status']);
        self::assertSame('SUPPLIER_NAME_DUPLICATE', $payload['code']);
        self::assertArrayHasKey('requestId', $payload);
    }

    /**
     * SUP-02: suspend через адмін-API переводить постачальника в suspended.
     */
    public function testSuspendEndpointChangesStatus(): void
    {
        $created = self::decode($this->json('POST', '/api/admin/v1/suppliers', ['name' => 'ТОВ «Логістик Плюс»']));

        $response = $this->json('POST', '/api/admin/v1/suppliers/'.$created['id'].'/suspend', [
            'reason' => 'Заборгованість',
        ]);

        $payload = self::decode($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('suspended', $payload['status']);
        self::assertSame('Заборгованість', $payload['suspendReason']);
    }

    /**
     * SUP-BOOK-02/03: номер нормалізується на сервері.
     */
    public function testVehicleIsCreatedWithNormalizedPlateNumber(): void
    {
        $response = $this->json('POST', '/api/supplier/v1/vehicles', [
            'plateNumber' => 'аа 1234 вв',
            'weightTons' => 12.5,
            'brand' => 'Renault',
        ], 'sp-1');

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertSame('АА1234ВВ', $payload['plateNumber']);
        self::assertSame(12.5, $payload['weightTons']);
    }

    /**
     * SUP-VEH-02 через HTTP: у межах постачальника — 409,
     * у різних постачальників — обидва створюються.
     */
    public function testPlateUniquenessIsScopedToSupplierOverHttp(): void
    {
        $this->json('POST', '/api/supplier/v1/vehicles', ['plateNumber' => 'AA1234BB', 'weightTons' => 12], 'sp-1');

        $duplicate = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'aa1234bb', 'weightTons' => 12],
            'sp-1',
        );
        $otherSupplier = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 12],
            'sp-2',
        );

        self::assertSame(Response::HTTP_CONFLICT, $duplicate->getStatusCode());
        self::assertSame('VEHICLE_PLATE_DUPLICATE', self::decode($duplicate)['code']);
        self::assertSame(Response::HTTP_CREATED, $otherSupplier->getStatusCode());
    }

    /**
     * DATA-34: діапазон 0.5–40 т, помилка 422.
     */
    public function testVehicleWeightOutOfRangeReturns422(): void
    {
        $response = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 55],
            'sp-1',
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('VEHICLE_WEIGHT_OUT_OF_RANGE', self::decode($response)['code']);
    }

    /**
     * Контур partner завжди працює в межах постачальника з токена:
     * без заголовка від api-gateway запит не обслуговується.
     */
    public function testSupplierScopedEndpointRequiresSupplierContext(): void
    {
        $response = $this->json('GET', '/api/supplier/v1/vehicles');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('SUPPLIER_CONTEXT_MISSING', self::decode($response)['code']);
    }

    /**
     * SUP-DRV-03: пароль повертається один раз разом із поясненням для UI.
     */
    public function testDriverCreationReturnsPasswordOnce(): void
    {
        $supplier = self::decode($this->json('POST', '/api/admin/v1/suppliers', ['name' => 'ТОВ «Логістик Плюс»']));

        $response = $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '067 111 22 33',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], $supplier['id']);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertSame('+380671112233', $payload['phone']);
        self::assertSame('+380671112233', $payload['login']);
        self::assertSame(12, \strlen($payload['password']));
        self::assertStringContainsString('Запишіть пароль', $payload['passwordNotice']);

        // Повторне читання водія пароля вже не містить.
        $reread = self::decode($this->json('GET', '/api/supplier/v1/drivers/'.$payload['id'], null, $supplier['id']));
        self::assertArrayNotHasKey('password', $reread);
    }

    /**
     * DATA-17 через HTTP: телефон водія унікальний глобально.
     */
    public function testDuplicateDriverPhoneAcrossSuppliersReturns409(): void
    {
        $first = self::decode($this->json('POST', '/api/admin/v1/suppliers', ['name' => 'Перший']));
        $second = self::decode($this->json('POST', '/api/admin/v1/suppliers', ['name' => 'Другий']));

        $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '+380671112233',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], $first['id']);

        $response = $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '0671112233',
            'firstName' => 'Петро',
            'lastName' => 'Шевчук',
        ], $second['id']);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('DRIVER_PHONE_DUPLICATE', self::decode($response)['code']);
    }

    public function testDriverListIsScopedToSupplierFromContext(): void
    {
        $supplier = self::decode($this->json('POST', '/api/admin/v1/suppliers', ['name' => 'ТОВ «Логістик Плюс»']));

        $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '+380671112233',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], $supplier['id']);

        $own = self::decode($this->json('GET', '/api/supplier/v1/drivers', null, $supplier['id']));
        $foreign = self::decode($this->json('GET', '/api/supplier/v1/drivers', null, 'sp-foreign'));

        self::assertSame(1, $own['total']);
        self::assertSame(0, $foreign['total']);
    }

    public function testUnknownSupplierReturns404ProblemJson(): void
    {
        $response = $this->json('GET', '/api/admin/v1/suppliers/sp-unknown');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('SUPPLIER_NOT_FOUND', self::decode($response)['code']);
    }

    public function testMalformedJsonBodyReturns422WithCode(): void
    {
        $request = Request::create(
            '/api/admin/v1/suppliers',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{зламаний json',
        );

        $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, catch: true);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('REQUEST_BODY_INVALID', self::decode($response)['code']);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function json(string $method, string $uri, ?array $body = null, ?string $supplierId = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $supplierId) {
            $server[self::SUPPLIER_HEADER] = $supplierId;
        }

        $request = Request::create(
            $uri,
            $method,
            server: $server,
            content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, catch: true);
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
