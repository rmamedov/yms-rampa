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
 * формат помилок RFC 7807, наскрізні правила довідників і — окремо — права
 * доступу за єдиним контрактом ідентичності.
 *
 * Запити подаються прямо в ядро (без browser-kit), тому тест не потребує
 * ані вебсервера, ані MongoDB.
 */
final class ApiEndpointsTest extends KernelTestCase
{
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
        ], self::staff('super_admin'));

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
        $this->givenSupplier('ТОВ «Логістик Плюс»');

        $response = $this->json(
            'POST',
            '/api/admin/v1/suppliers',
            ['name' => 'ТОВ «Логістик Плюс»'],
            self::staff('super_admin'),
        );

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
        $created = $this->givenSupplier('ТОВ «Логістик Плюс»');

        $response = $this->json(
            'POST',
            '/api/admin/v1/suppliers/'.$created['id'].'/suspend',
            ['reason' => 'Заборгованість'],
            self::staff('network_manager'),
        );

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
        ], self::supplier('supplier_admin', 'sp-1'));

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
        $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 12],
            self::supplier('supplier_admin', 'sp-1'),
        );

        $duplicate = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'aa1234bb', 'weightTons' => 12],
            self::supplier('supplier_admin', 'sp-1'),
        );
        $otherSupplier = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 12],
            self::supplier('supplier_admin', 'sp-2'),
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
            self::supplier('supplier_admin', 'sp-1'),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('VEHICLE_WEIGHT_OUT_OF_RANGE', self::decode($response)['code']);
    }

    /**
     * SUP-DRV-03: пароль повертається один раз разом із поясненням для UI.
     */
    public function testDriverCreationReturnsPasswordOnce(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');
        $identity = self::supplier('supplier_admin', $supplier['id']);

        $response = $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '067 111 22 33',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], $identity);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertSame('+380671112233', $payload['phone']);
        self::assertSame('+380671112233', $payload['login']);
        self::assertSame(12, \strlen($payload['password']));
        self::assertStringContainsString('Запишіть пароль', $payload['passwordNotice']);

        // Повторне читання водія пароля вже не містить.
        $reread = self::decode($this->json('GET', '/api/supplier/v1/drivers/'.$payload['id'], null, $identity));
        self::assertArrayNotHasKey('password', $reread);
    }

    /**
     * DATA-17 через HTTP: телефон водія унікальний глобально.
     */
    public function testDuplicateDriverPhoneAcrossSuppliersReturns409(): void
    {
        $first = $this->givenSupplier('Перший');
        $second = $this->givenSupplier('Другий');

        $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '+380671112233',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], self::supplier('supplier_admin', $first['id']));

        $response = $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '0671112233',
            'firstName' => 'Петро',
            'lastName' => 'Шевчук',
        ], self::supplier('supplier_admin', $second['id']));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('DRIVER_PHONE_DUPLICATE', self::decode($response)['code']);
    }

    public function testDriverListIsScopedToSupplierFromContext(): void
    {
        $supplier = $this->givenSupplier('ТОВ «Логістик Плюс»');

        $this->json('POST', '/api/supplier/v1/drivers', [
            'phone' => '+380671112233',
            'firstName' => 'Іван',
            'lastName' => 'Коваль',
        ], self::supplier('supplier_admin', $supplier['id']));

        $own = self::decode($this->json(
            'GET',
            '/api/supplier/v1/drivers',
            null,
            self::supplier('supplier_admin', $supplier['id']),
        ));
        $foreign = self::decode($this->json(
            'GET',
            '/api/supplier/v1/drivers',
            null,
            self::supplier('supplier_admin', 'sp-foreign'),
        ));

        self::assertSame(1, $own['total']);
        self::assertSame(0, $foreign['total']);
    }

    /**
     * Ізоляція тенантів на читанні одиночного ресурсу: чуже авто не існує
     * для іншого постачальника (404 без розкриття факту існування).
     */
    public function testForeignVehicleIsNotReachableById(): void
    {
        $created = self::decode($this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 12],
            self::supplier('supplier_admin', 'sp-1'),
        ));

        $response = $this->json(
            'GET',
            '/api/supplier/v1/vehicles/'.$created['id'],
            null,
            self::supplier('supplier_admin', 'sp-2'),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('VEHICLE_NOT_FOUND', self::decode($response)['code']);
    }

    public function testUnknownSupplierReturns404ProblemJson(): void
    {
        $response = $this->json('GET', '/api/admin/v1/suppliers/sp-unknown', null, self::staff('super_admin'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('SUPPLIER_NOT_FOUND', self::decode($response)['code']);
    }

    public function testMalformedJsonBodyReturns422WithCode(): void
    {
        $request = Request::create(
            '/api/admin/v1/suppliers',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'] + self::staff('super_admin'),
            content: '{зламаний json',
        );

        $response = $this->httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, catch: true);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('REQUEST_BODY_INVALID', self::decode($response)['code']);
    }

    // --- Права доступу за єдиним контрактом ідентичності -------------------

    /**
     * Запит без заголовків ідентичності не обслуговується взагалі:
     * у прод сюди приходять лише запити, які пройшли шлюз.
     */
    public function testEndpointsWithoutIdentityAreDenied(): void
    {
        foreach (['/api/supplier/v1/vehicles', '/api/supplier/v1/drivers', '/api/admin/v1/suppliers'] as $uri) {
            $response = $this->json('GET', $uri);

            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $uri);
            self::assertSame('ACCESS_DENIED', self::decode($response)['code'], $uri);
        }
    }

    /**
     * КЛЮЧОВИЙ НЕГАТИВНИЙ ТЕСТ: порожній X-Supplier-Id для ролі постачальника —
     * це ВІДМОВА, а не доступ до даних усіх постачальників.
     */
    public function testSupplierRoleWithEmptySupplierHeaderIsDenied(): void
    {
        foreach (['supplier_admin', 'supplier_operator'] as $role) {
            foreach (['/api/supplier/v1/vehicles', '/api/supplier/v1/drivers'] as $uri) {
                $response = $this->json('GET', $uri, null, self::supplier($role, ''));

                self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $role.' '.$uri);
                self::assertSame('ACCESS_DENIED', self::decode($response)['code'], $role.' '.$uri);
            }
        }
    }

    /**
     * Так само й на запис: без постачальника в ідентичності створити нічого
     * не можна (інакше запис пішов би «в нікуди» або в чужий скоуп).
     */
    public function testSupplierRoleWithEmptySupplierHeaderCannotCreateVehicle(): void
    {
        $response = $this->json(
            'POST',
            '/api/supplier/v1/vehicles',
            ['plateNumber' => 'AA1234BB', 'weightTons' => 12],
            self::supplier('supplier_admin', ''),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($response)['code']);
    }

    /**
     * Водій має свій постачальник у токені, але кабінет постачальника —
     * не його розділ (у матриці 4.4 driver не має ні vehicle.manage,
     * ні driver.manage).
     */
    public function testDriverRoleCannotUseSupplierCabinet(): void
    {
        foreach (['/api/supplier/v1/vehicles', '/api/supplier/v1/drivers'] as $uri) {
            $response = $this->json('GET', $uri, null, self::supplier('driver', 'sp-1'));

            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $uri);
            self::assertSame('ACCESS_DENIED', self::decode($response)['code'], $uri);
        }
    }

    /**
     * Матриця 4.4: vehicle.manage — supplier_admin і supplier_operator,
     * driver.manage — лише supplier_admin.
     */
    public function testSupplierOperatorManagesVehiclesButNotDrivers(): void
    {
        $operator = self::supplier('supplier_operator', 'sp-1');

        $vehicles = $this->json('GET', '/api/supplier/v1/vehicles', null, $operator);
        $drivers = $this->json('GET', '/api/supplier/v1/drivers', null, $operator);

        self::assertSame(Response::HTTP_OK, $vehicles->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $drivers->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($drivers)['code']);
    }

    /**
     * Партнерська роль не потрапляє в адмін-розділ навіть із валідною
     * ідентичністю свого контуру.
     */
    public function testPartnerRoleCannotReachAdminApi(): void
    {
        $response = $this->json('GET', '/api/admin/v1/suppliers', null, self::supplier('supplier_admin', 'sp-1'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($response)['code']);
    }

    /**
     * Матриця 4.4: analyst читає постачальників, але не змінює їх.
     */
    public function testAnalystReadsSuppliersButCannotManageThem(): void
    {
        $read = $this->json('GET', '/api/admin/v1/suppliers', null, self::staff('analyst'));
        $write = $this->json('POST', '/api/admin/v1/suppliers', ['name' => 'Нова'], self::staff('analyst'));

        self::assertSame(Response::HTTP_OK, $read->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $write->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($write)['code']);
    }

    /**
     * Магазинні ролі не мають доступу до довідника постачальників — ні з
     * порожнім переліком магазинів, ні з непорожнім. Порожній X-Store-Ids
     * НІКОЛИ не розширює доступ (RBAC-13).
     */
    public function testStoreRolesCannotReachSupplierDirectory(): void
    {
        foreach (['store_manager', 'store_operator'] as $role) {
            foreach (['', 'S-01,S-02'] as $storeIds) {
                $response = $this->json('GET', '/api/admin/v1/suppliers', null, self::staff($role, $storeIds));

                self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), $role.' ['.$storeIds.']');
                self::assertSame('ACCESS_DENIED', self::decode($response)['code'], $role.' ['.$storeIds.']');
            }
        }
    }

    public function testUnknownRoleIsDenied(): void
    {
        $response = $this->json('GET', '/api/admin/v1/suppliers', null, [
            'HTTP_X-User-Id' => 'u-1',
            'HTTP_X-User-Role' => 'root',
            'HTTP_X-Supplier-Id' => '',
            'HTTP_X-Store-Ids' => '',
            'HTTP_X-Contour' => 'staff',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($response)['code']);
    }

    /**
     * Заголовок ролі — рівно X-User-Role з єдиного контракту. Історичний
     * X-Partner-Role більше не читається, тож ідентичності в такому запиті
     * немає — 403, а не мовчазний доступ.
     */
    public function testLegacyPartnerRoleHeaderIsNotHonoured(): void
    {
        $response = $this->json('GET', '/api/supplier/v1/vehicles', null, [
            'HTTP_X-Supplier-Id' => 'sp-1',
            'HTTP_X-Partner-Role' => 'supplier_admin',
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($response)['code']);
    }

    /**
     * Контур із заголовка має збігатися з контуром ролі — інакше відмова.
     */
    public function testContourMismatchIsDenied(): void
    {
        $identity = self::supplier('supplier_admin', 'sp-1');
        $identity['HTTP_X-Contour'] = 'staff';

        $response = $this->json('GET', '/api/supplier/v1/vehicles', null, $identity);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('ACCESS_DENIED', self::decode($response)['code']);
    }

    // --- інфраструктура тесту ---------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function givenSupplier(string $name): array
    {
        return self::decode($this->json(
            'POST',
            '/api/admin/v1/suppliers',
            ['name' => $name],
            self::staff('super_admin'),
        ));
    }

    /**
     * Заголовки staff-контуру. X-Supplier-Id для них «не застосовний» —
     * порожній рядок, як і віддає identity-staff-service.
     *
     * @return array<string, string>
     */
    private static function staff(string $role, string $storeIds = ''): array
    {
        return self::identity($role, 'staff', '', $storeIds);
    }

    /**
     * Заголовки partner-контуру.
     *
     * @return array<string, string>
     */
    private static function supplier(string $role, string $supplierId): array
    {
        return self::identity($role, 'partner', $supplierId);
    }

    /**
     * Рівно пʼять заголовків єдиного контракту — саме такий набір
     * примусово підставляє шлюз, включно з порожніми значеннями.
     *
     * @return array<string, string>
     */
    private static function identity(
        string $role,
        string $contour,
        string $supplierId = '',
        string $storeIds = '',
    ): array {
        return [
            'HTTP_X-User-Id' => 'u-'.$role,
            'HTTP_X-User-Role' => $role,
            'HTTP_X-Supplier-Id' => $supplierId,
            'HTTP_X-Store-Ids' => $storeIds,
            'HTTP_X-Contour' => $contour,
        ];
    }

    /**
     * @param array<string, mixed>|null  $body
     * @param array<string, string>|null $identity заголовки ідентичності від шлюзу
     */
    private function json(string $method, string $uri, ?array $body = null, ?array $identity = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'] + ($identity ?? []);

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
