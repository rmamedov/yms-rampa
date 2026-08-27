<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Actor;
use App\Domain\Access\Contour;
use App\Domain\Access\Role;
use App\Infrastructure\Http\ActorResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Єдиний контракт службових заголовків ідентичності: X-User-Id, X-User-Role
 * (рівно одна роль), X-Supplier-Id, X-Store-Ids (через кому), X-Contour.
 */
#[CoversClass(ActorResolver::class)]
#[CoversClass(Actor::class)]
#[CoversClass(Role::class)]
final class ActorResolverTest extends TestCase
{
    private ActorResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ActorResolver();
    }

    #[Test]
    public function readsFullStaffIdentityFromHeaders(): void
    {
        $actor = $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'store_manager',
            'X-Supplier-Id' => '',
            'X-Store-Ids' => 'S-01,S-02',
            'X-Contour' => 'staff',
        ]));

        self::assertSame('u-1', $actor->userId);
        self::assertSame(Role::StoreManager, $actor->role);
        self::assertSame(Contour::Staff, $actor->contour);
        self::assertNull($actor->supplierId);
        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
    }

    #[Test]
    public function emptyStoreHeaderYieldsEmptyScopeNotWholeNetwork(): void
    {
        $actor = $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'store_operator',
            'X-Store-Ids' => '',
            'X-Contour' => 'staff',
        ]));

        self::assertSame([], $actor->storeIds);
        // RBAC-13: порожній перелік — це нуль доступу, а не «будь-який магазин».
        self::assertFalse($actor->canAccessStore('S-01'));
    }

    #[Test]
    public function networkRoleCarriesEmptyStoreHeaderYetSeesAnyStore(): void
    {
        $actor = $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-2',
            'X-User-Role' => 'analyst',
            'X-Store-Ids' => '',
            'X-Contour' => 'staff',
        ]));

        self::assertTrue($actor->canAccessStore('S-01'));
        self::assertTrue($actor->canAccessStore('S-99'));
    }

    #[Test]
    public function requestWithoutIdentityHeadersIsRejected(): void
    {
        try {
            $this->resolver->fromRequest($this->request([]));
            self::fail('Очікувалася відмова: запит без ідентичності.');
        } catch (AccessDeniedException $exception) {
            self::assertSame('ANALYTICS_ACCESS_DENIED', $exception->errorCode());
            self::assertSame(403, $exception->httpStatus());
        }
    }

    #[Test]
    public function unknownRoleIsRejected(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'store_admin',
            'X-Contour' => 'staff',
        ]));
    }

    #[Test]
    public function multipleRolesInOneHeaderAreRejected(): void
    {
        // Контракт: рівно ОДНА роль, а не перелік.
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'analyst,store_manager',
            'X-Contour' => 'staff',
        ]));
    }

    #[Test]
    public function supplierRoleWithEmptySupplierHeaderIsRejected(): void
    {
        try {
            $this->resolver->fromRequest($this->request([
                'X-User-Id' => 'u-3',
                'X-User-Role' => 'supplier_admin',
                'X-Supplier-Id' => '',
                'X-Contour' => 'partner',
            ]));
            self::fail('Очікувалася відмова: постачальник без X-Supplier-Id.');
        } catch (AccessDeniedException $exception) {
            self::assertStringContainsString('X-Supplier-Id', $exception->getMessage());
        }
    }

    #[Test]
    public function contourHeaderMustMatchTheRoleItself(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-4',
            'X-User-Role' => 'supplier_operator',
            'X-Supplier-Id' => 'sup-1',
            // Роль partner-контуру під виглядом staff — відмова.
            'X-Contour' => 'staff',
        ]));
    }

    #[Test]
    public function supplierIdentityIsResolvedButCannotReadAnalytics(): void
    {
        $actor = $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-5',
            'X-User-Role' => 'supplier_admin',
            'X-Supplier-Id' => 'sup-1',
            'X-Store-Ids' => '',
            'X-Contour' => 'partner',
        ]));

        self::assertSame('sup-1', $actor->supplierId);
        self::assertFalse($actor->canReadAnalytics());
        self::assertFalse($actor->canAccessStore('S-01'));
    }

    #[Test]
    public function storeIdsListToleratesStrayWhitespaceAndDuplicates(): void
    {
        $actor = $this->resolver->fromRequest($this->request([
            'X-User-Id' => 'u-6',
            'X-User-Role' => 'store_manager',
            'X-Store-Ids' => 'S-01, S-02 ,S-01,',
            'X-Contour' => 'staff',
        ]));

        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Request
    {
        $request = Request::create('/api/admin/v1/analytics/kpi');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
