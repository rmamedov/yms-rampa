<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Controller\Store\WalkInController;
use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Role;
use App\Infrastructure\Http\ActorResolver;
use App\Tests\Support\Scenario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Єдиний контракт ідентичності у службових заголовках шлюзу:
 * X-User-Id, X-User-Role (рівно одна роль), X-Supplier-Id, X-Store-Ids
 * (перелік через кому, порожній рядок = порожній перелік).
 */
#[CoversClass(ActorResolver::class)]
final class ActorResolverTest extends TestCase
{
    public function testStoreIdsHeaderIsParsedAsList(): void
    {
        $actor = $this->resolve('su-1', 'store_manager', storeIds: 'S-01,S-02');

        self::assertSame(Role::StoreManager, $actor->role);
        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
        self::assertTrue($actor->canOperateStore('S-01'));
        self::assertTrue($actor->canOperateStore('S-02'));
        self::assertFalse($actor->canOperateStore('S-03'));
    }

    /** RBAC-13: порожній X-Store-Ids = нуль магазинів, а не «усі магазини». */
    #[DataProvider('emptyStoreScopeHeaders')]
    public function testEmptyStoreIdsHeaderGivesZeroStoreAccess(?string $header): void
    {
        $actor = $this->resolve('su-1', 'store_operator', storeIds: $header);

        self::assertSame([], $actor->storeIds);
        self::assertFalse($actor->canOperateStore(Scenario::STORE_ID));
        self::assertFalse($actor->canOperateStore('S-01'));
    }

    /** Мережева роль без переліку магазинів працює в будь-якій філії. */
    public function testNetworkRoleWithoutStoreIdsHeaderOperatesAnyStore(): void
    {
        $actor = $this->resolve('ad-1', 'network_manager');

        self::assertSame([], $actor->storeIds);
        self::assertTrue($actor->canOperateStore(Scenario::STORE_ID));
        self::assertTrue($actor->canOperateStore('S-42'));
    }

    /** Порожній X-Supplier-Id для ролі постачальника — відмова. */
    #[DataProvider('emptySupplierHeaders')]
    public function testSupplierRoleWithEmptySupplierHeaderIsRejected(?string $header): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->resolve('pu-1', 'supplier_admin', supplierId: $header);
    }

    public function testSupplierHeaderIsIgnoredForStaffRoles(): void
    {
        $actor = $this->resolve('su-1', 'store_manager', supplierId: '', storeIds: 'S-01');

        self::assertNull($actor->supplierId);
    }

    /**
     * Наскрізна регресія дефекту: магазинний користувач без магазинів
     * у заголовку не реєструє walk-in у жодній філії.
     */
    public function testWalkInIsDeniedForStoreUserWithEmptyStoreScope(): void
    {
        $scenario = new Scenario();
        $controller = new WalkInController($scenario->creation, new ActorResolver(), $scenario->clock);

        $request = $this->walkInRequest();
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'store_manager');
        $request->headers->set(ActorResolver::STORES_HEADER, '');

        $this->expectException(AccessDeniedException::class);
        $controller($request);
    }

    /** Той самий запит із «чужим» магазином у скоупі — теж відмова. */
    public function testWalkInIsDeniedForStoreUserScopedToAnotherStore(): void
    {
        $scenario = new Scenario();
        $controller = new WalkInController($scenario->creation, new ActorResolver(), $scenario->clock);

        $request = $this->walkInRequest();
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'store_manager');
        $request->headers->set(ActorResolver::STORES_HEADER, 'store-9,store-10');

        $this->expectException(AccessDeniedException::class);
        $controller($request);
    }

    /** Свій магазин у переліку — walk-in створюється. */
    public function testWalkInIsAllowedForStoreUserScopedToThisStore(): void
    {
        $scenario = new Scenario();
        $controller = new WalkInController($scenario->creation, new ActorResolver(), $scenario->clock);

        $request = $this->walkInRequest();
        $request->headers->set(ActorResolver::USER_HEADER, 'su-1');
        $request->headers->set(ActorResolver::ROLE_HEADER, 'store_manager');
        $request->headers->set(ActorResolver::STORES_HEADER, 'store-9,'.Scenario::STORE_ID);

        $response = $controller($request);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptyStoreScopeHeaders(): iterable
    {
        yield 'заголовка немає' => [null];
        yield 'порожній рядок' => [''];
        yield 'самі роздільники' => [' , , '];
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function emptySupplierHeaders(): iterable
    {
        yield 'заголовка немає' => [null];
        yield 'порожній рядок' => [''];
        yield 'самі пробіли' => ['   '];
    }

    private function resolve(
        string $userId,
        string $role,
        ?string $supplierId = null,
        ?string $storeIds = null,
    ): \App\Domain\Access\Actor {
        $request = Request::create('/api/store/v1/bookings/walk-in', 'POST');
        $request->headers->set(ActorResolver::USER_HEADER, $userId);
        $request->headers->set(ActorResolver::ROLE_HEADER, $role);

        if (null !== $supplierId) {
            $request->headers->set(ActorResolver::SUPPLIER_HEADER, $supplierId);
        }

        if (null !== $storeIds) {
            $request->headers->set(ActorResolver::STORES_HEADER, $storeIds);
        }

        return (new ActorResolver())->fromRequest($request);
    }

    private function walkInRequest(): Request
    {
        return Request::create(
            '/api/store/v1/bookings/walk-in',
            'POST',
            content: json_encode([
                'storeId' => Scenario::STORE_ID,
                'rampId' => 'r1',
                'slotStart' => '2026-08-27T06:00:00Z',
                'vehicle' => ['plateNumber' => 'BC5555CT', 'weightTons' => 3.5],
                'palletsCount' => 4,
                'supplierName' => 'ФОП Іваненко',
            ], \JSON_THROW_ON_ERROR),
        );
    }
}
