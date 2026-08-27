<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Domain\Access\AccessDeniedException;
use App\Domain\Access\Contour;
use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Infrastructure\Http\ActorResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Читання пʼяти службових заголовків єдиного контракту ідентичності.
 */
final class ActorResolverTest extends TestCase
{
    private ActorResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ActorResolver();
    }

    public function testStaffIdentityIsReadFromContractHeaders(): void
    {
        $actor = $this->resolver->fromRequest(self::request([
            'X-User-Id' => 'u-42',
            'X-User-Role' => 'store_manager',
            'X-Supplier-Id' => '',
            'X-Store-Ids' => 'S-01,S-02',
            'X-Contour' => 'staff',
        ]));

        self::assertSame('u-42', $actor->userId);
        self::assertSame(Role::StoreManager, $actor->role);
        self::assertSame(Contour::Staff, $actor->contour);
        self::assertNull($actor->supplierId);
        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
    }

    public function testPartnerIdentityIsReadFromContractHeaders(): void
    {
        $actor = $this->resolver->fromRequest(self::request([
            'X-User-Id' => 'u-7',
            'X-User-Role' => 'supplier_operator',
            'X-Supplier-Id' => 'sp-1',
            'X-Store-Ids' => '',
            'X-Contour' => 'partner',
        ]));

        self::assertSame(Role::SupplierOperator, $actor->role);
        self::assertSame(Contour::Partner, $actor->contour);
        self::assertSame('sp-1', $actor->supplierId);
        self::assertSame([], $actor->storeIds);
    }

    /**
     * Порожній рядок означає «не застосовно» — і для магазинів це порожній
     * перелік, тобто нуль доступу для магазинної ролі (RBAC-13), а не «всі».
     */
    public function testEmptyStoreHeaderYieldsEmptyScope(): void
    {
        $actor = $this->resolver->fromRequest(self::request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'store_operator',
            'X-Store-Ids' => '',
            'X-Contour' => 'staff',
        ]));

        self::assertSame([], $actor->storeIds);
        self::assertFalse($actor->canAccessStore('S-01'));
    }

    public function testStoreListIsSplitOnCommasWithoutBlanksOrDuplicates(): void
    {
        $actor = $this->resolver->fromRequest(self::request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'store_manager',
            'X-Store-Ids' => 'S-01, S-02,,S-01',
            'X-Contour' => 'staff',
        ]));

        self::assertSame(['S-01', 'S-02'], $actor->storeIds);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('incompleteIdentities')]
    public function testIncompleteIdentityIsDenied(array $headers): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->fromRequest(self::request($headers));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteIdentities(): iterable
    {
        yield 'без заголовків узагалі' => [[]];

        yield 'без X-User-Id' => [[
            'X-User-Role' => 'super_admin',
            'X-Contour' => 'staff',
        ]];

        yield 'без X-User-Role' => [[
            'X-User-Id' => 'u-1',
            'X-Contour' => 'staff',
        ]];

        yield 'порожній X-User-Role' => [[
            'X-User-Id' => 'u-1',
            'X-User-Role' => '',
            'X-Contour' => 'staff',
        ]];

        yield 'невідома роль' => [[
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'root',
            'X-Contour' => 'staff',
        ]];

        yield 'нерозпізнаний контур' => [[
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'super_admin',
            'X-Contour' => 'internal',
        ]];

        yield 'контур не збігається з роллю' => [[
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'supplier_admin',
            'X-Supplier-Id' => 'sp-1',
            'X-Contour' => 'staff',
        ]];

        yield 'роль постачальника без X-Supplier-Id' => [[
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'supplier_admin',
            'X-Supplier-Id' => '',
            'X-Contour' => 'partner',
        ]];

        yield 'історичний X-Partner-Role більше не діє' => [[
            'X-Partner-Role' => 'supplier_admin',
            'X-Supplier-Id' => 'sp-1',
        ]];
    }

    /**
     * Порожній X-Supplier-Id для ролі постачальника — відмова, а не тихий
     * доступ до всіх постачальників.
     */
    public function testOwnSupplierIdRequiresNonEmptyHeader(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->resolver->ownSupplierId(
            self::request([
                'X-User-Id' => 'u-1',
                'X-User-Role' => 'supplier_operator',
                'X-Supplier-Id' => '',
                'X-Contour' => 'partner',
            ]),
            Permission::VehicleManage,
        );
    }

    public function testOwnSupplierIdComesFromIdentityOnly(): void
    {
        $supplierId = $this->resolver->ownSupplierId(
            self::request([
                'X-User-Id' => 'u-1',
                'X-User-Role' => 'supplier_admin',
                'X-Supplier-Id' => 'sp-9',
                'X-Contour' => 'partner',
            ]),
            Permission::DriverManage,
        );

        self::assertSame('sp-9', $supplierId);
    }

    /**
     * Відсутній X-Contour (старіше розгортання шлюзу) — контур виводиться
     * з ролі, а не відкриває доступ навмання.
     */
    public function testMissingContourHeaderFallsBackToRoleContour(): void
    {
        $actor = $this->resolver->fromRequest(self::request([
            'X-User-Id' => 'u-1',
            'X-User-Role' => 'analyst',
        ]));

        self::assertSame(Contour::Staff, $actor->contour);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(array $headers): Request
    {
        $request = Request::create('/api/supplier/v1/vehicles');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
