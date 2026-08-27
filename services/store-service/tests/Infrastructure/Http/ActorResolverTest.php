<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use App\Infrastructure\Http\ActorResolver;
use App\Tests\Support\IdentityHeaders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Єдиний контракт службових заголовків ідентичності:
 * X-User-Id, X-User-Role, X-Supplier-Id, X-Store-Ids, X-Contour.
 *
 * Заголовка X-Supplier-Stores у контракті НЕМАЄ — і саме його відсутність
 * колись означала «всі магазини»; тепер перелік читається з X-Store-Ids.
 */
#[CoversClass(ActorResolver::class)]
final class ActorResolverTest extends TestCase
{
    private ActorResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ActorResolver();
    }

    public function testReadsFullStaffIdentity(): void
    {
        $actor = $this->resolver->fromRequest(self::request(IdentityHeaders::staff(
            role: 'store_manager',
            storeIds: ['S-01', 'S-02'],
            userId: 'staff-42',
        )));

        self::assertSame('staff-42', $actor->userId);
        self::assertSame(Role::StoreManager, $actor->role);
        self::assertSame(Contour::Staff, $actor->contour);
        self::assertNull($actor->supplierId, 'у staff-контурі постачальника не існує');
        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
    }

    public function testReadsFullPartnerIdentity(): void
    {
        $actor = $this->resolver->fromRequest(self::request(IdentityHeaders::supplier(supplierId: 'sup-7')));

        self::assertSame(Role::SupplierOperator, $actor->role);
        self::assertSame(Contour::Partner, $actor->contour);
        self::assertSame('sup-7', $actor->supplierId);
        self::assertSame([], $actor->storeIds);
    }

    /** Контракт: перелік через кому БЕЗ пробілів; зайві пробіли все ж толеруються. */
    #[DataProvider('storeIdsProvider')]
    public function testParsesStoreIdsHeader(string $header, array $expected): void
    {
        $actor = $this->resolver->fromRequest(self::request(
            IdentityHeaders::raw('staff-1', 'store_manager', storeIds: $header, contour: 'staff'),
        ));

        self::assertSame($expected, $actor->storeIds);
    }

    /**
     * RBAC-13: порожній заголовок дає порожній перелік — і для магазинної ролі
     * це НУЛЬ доступу, а не «будь-який магазин».
     */
    public function testEmptyStoreIdsHeaderIsZeroAccessForStoreRole(): void
    {
        $actor = $this->resolver->fromRequest(self::request(IdentityHeaders::staff(role: 'store_operator')));

        self::assertSame([], $actor->storeIds);
        self::assertSame([], $actor->storeScope());
        self::assertFalse($actor->canAccessStore('S-01'));
    }

    /** Ігнорується заголовок поза контрактом: whitelist більше не читається з X-Supplier-Stores. */
    public function testLegacySupplierStoresHeaderIsIgnored(): void
    {
        $headers = IdentityHeaders::supplier();
        $headers['HTTP_X_SUPPLIER_STORES'] = 'S-01';

        $actor = $this->resolver->fromRequest(self::request($headers));

        self::assertSame([], $actor->storeIds);
    }

    public function testRequestWithoutIdentityIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest(Request::create('/api/admin/v1/stores'));
    }

    public function testUnknownRoleIsDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest(self::request(IdentityHeaders::raw('staff-1', 'root', contour: 'staff')));
    }

    /** RBAC-14: роль постачальника без X-Supplier-Id — відмова. */
    #[DataProvider('partnerRoleProvider')]
    public function testPartnerRoleWithoutSupplierIdIsDenied(string $role): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest(self::request(
            IdentityHeaders::raw('partner-1', $role, supplierId: '', contour: 'partner'),
        ));
    }

    public function testContourHeaderMustAgreeWithRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest(self::request(
            IdentityHeaders::raw('staff-1', 'store_manager', contour: 'partner'),
        ));
    }

    /** RBAC-19: partner-роль не проходить у staff-маршрути і навпаки. */
    public function testStaffRoutesRejectPartnerRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->staff(self::request(IdentityHeaders::supplier()));
    }

    public function testSupplierRoutesRejectStaffRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->supplier(self::request(IdentityHeaders::staff()));
    }

    /** Каталог магазинів supplier-web адресований кабінету постачальника, а не водієві. */
    public function testSupplierRoutesRejectDriver(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->supplier(self::request(IdentityHeaders::supplier(role: 'driver')));
    }

    public function testStaffAndSupplierGatesPassOwnContour(): void
    {
        self::assertSame(Role::SuperAdmin, $this->resolver->staff(self::request(IdentityHeaders::staff()))->role);
        self::assertSame(
            Role::SupplierAdmin,
            $this->resolver->supplier(self::request(IdentityHeaders::supplier(role: 'supplier_admin')))->role,
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function storeIdsProvider(): iterable
    {
        yield 'канонічний перелік через кому' => ['S-01,S-02', ['S-01', 'S-02']];
        yield 'один магазин' => ['S-01', ['S-01']];
        yield 'порожній рядок' => ['', []];
        yield 'пробіли і порожні елементи' => [' S-01 , ,S-02 ', ['S-01', 'S-02']];
        yield 'дублікати згортаються' => ['S-01,S-01', ['S-01']];
    }

    /** @return iterable<string, array{string}> */
    public static function partnerRoleProvider(): iterable
    {
        yield 'supplier_admin' => ['supplier_admin'];
        yield 'supplier_operator' => ['supplier_operator'];
        yield 'driver' => ['driver'];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers): Request
    {
        return Request::create('/api/admin/v1/stores', 'GET', server: $headers);
    }
}
