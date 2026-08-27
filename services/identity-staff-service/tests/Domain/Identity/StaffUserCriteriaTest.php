<?php

declare(strict_types=1);

namespace App\Tests\Domain\Identity;

use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserCriteria;
use App\Infrastructure\InMemory\InMemoryStaffUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Критерії списку користувачів (4.7) і їх застосування у сховищі.
 */
#[CoversClass(StaffUserCriteria::class)]
#[CoversClass(InMemoryStaffUserRepository::class)]
final class StaffUserCriteriaTest extends TestCase
{
    private const string NOW = '2026-08-27T09:00:00+00:00';

    private function user(string $email, Role $role, string $fullName = 'Тестовий Користувач'): StaffUser
    {
        return StaffUser::create(
            email: Email::fromString($email),
            passwordHash: 'hash',
            role: $role,
            storeIds: [],
            now: new \DateTimeImmutable(self::NOW),
            fullName: $fullName,
        );
    }

    public function testDefaultsMatchTheRestOfAdminLists(): void
    {
        $criteria = StaffUserCriteria::fromQuery([]);

        self::assertNull($criteria->role);
        self::assertNull($criteria->active);
        self::assertSame(1, $criteria->page);
        self::assertSame(20, $criteria->perPage);
        self::assertSame(0, $criteria->offset());
    }

    public function testOffsetFollowsPageAndPerPage(): void
    {
        self::assertSame(100, StaffUserCriteria::fromQuery(['page' => 3, 'perPage' => 50])->offset());
        // Сторінка «0» або відʼємна прирівнюється до першої
        self::assertSame(0, StaffUserCriteria::fromQuery(['page' => -5])->offset());
    }

    public function testUnsupportedPageSizeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        StaffUserCriteria::fromQuery(['perPage' => 25]);
    }

    public function testUnknownRoleAndStatusAreRejected(): void
    {
        foreach ([['role' => 'wizard'], ['role' => 'driver'], ['status' => 'maybe']] as $query) {
            try {
                StaffUserCriteria::fromQuery($query);
                self::fail('Очікувалася відмова VALIDATION_FAILED.');
            } catch (ValidationException $exception) {
                self::assertSame('VALIDATION_FAILED', $exception->errorCode());
                self::assertSame(422, $exception->httpStatus());
            }
        }
    }

    /**
     * RBAC-23: перетин фільтра з дозволеними акторові ролями. Роль поза
     * деревом дає ПОРОЖНЮ вибірку, а не «фільтр не застосовано».
     */
    public function testEffectiveRolesIntersectFilterWithManageableRoles(): void
    {
        $manageable = [Role::StoreManager, Role::StoreOperator];

        self::assertSame(
            $manageable,
            StaffUserCriteria::fromQuery([])->restrictedTo($manageable)->effectiveRoles(),
        );

        self::assertSame(
            [Role::StoreManager],
            StaffUserCriteria::fromQuery(['role' => 'store_manager'])->restrictedTo($manageable)->effectiveRoles(),
        );

        self::assertSame(
            [],
            StaffUserCriteria::fromQuery(['role' => 'super_admin'])->restrictedTo($manageable)->effectiveRoles(),
        );

        self::assertSame([], StaffUserCriteria::fromQuery([])->restrictedTo([])->effectiveRoles());
    }

    public function testSearchMatchesEmailAndFullNameCaseInsensitively(): void
    {
        $user = $this->user('Olena@silpo.ua', Role::StoreManager, 'Олена Іванова');

        self::assertTrue(StaffUserCriteria::fromQuery(['q' => 'OLENA'])->matches($user));
        self::assertTrue(StaffUserCriteria::fromQuery(['q' => 'silpo'])->matches($user));
        self::assertTrue(StaffUserCriteria::fromQuery(['q' => 'іванова'])->matches($user));
        self::assertFalse(StaffUserCriteria::fromQuery(['q' => 'петро'])->matches($user));
    }

    public function testRepositoryAppliesFiltersOrderingAndPagination(): void
    {
        $repository = new InMemoryStaffUserRepository();

        $repository->save($this->user('zoya@silpo.ua', Role::StoreManager));
        $repository->save($this->user('anna@silpo.ua', Role::StoreOperator));
        $repository->save($this->user('mykola@silpo.ua', Role::Analyst));

        $fired = $this->user('fired@silpo.ua', Role::StoreOperator);
        $fired->deactivate(new \DateTimeImmutable(self::NOW));
        $repository->save($fired);

        // Активні першими, далі за email — порядок стабільний між запитами
        $all = $repository->search(StaffUserCriteria::fromQuery([]));
        self::assertSame(4, $all->total);
        self::assertSame(1, $all->pages());
        self::assertSame(
            ['anna@silpo.ua', 'mykola@silpo.ua', 'zoya@silpo.ua', 'fired@silpo.ua'],
            array_map(static fn (StaffUser $u): string => $u->email()->value, $all->items),
        );

        $active = $repository->search(StaffUserCriteria::fromQuery(['status' => 'active']));
        self::assertSame(3, $active->total);

        $inactive = $repository->search(StaffUserCriteria::fromQuery(['status' => 'inactive']));
        self::assertSame(1, $inactive->total);
        self::assertSame('fired@silpo.ua', $inactive->items[0]->email()->value);

        $operators = $repository->search(StaffUserCriteria::fromQuery(['role' => 'store_operator']));
        self::assertSame(2, $operators->total);

        // RBAC-23: порожній перелік дозволених ролей — гарантовано порожня вибірка
        $none = $repository->search(StaffUserCriteria::fromQuery([])->restrictedTo([]));
        self::assertSame(0, $none->total);
        self::assertTrue($none->isEmpty());
    }

    public function testPagesAreCountedFromTotal(): void
    {
        $repository = new InMemoryStaffUserRepository();

        for ($i = 0; $i < 21; ++$i) {
            $repository->save($this->user(\sprintf('user%02d@silpo.ua', $i), Role::StoreOperator));
        }

        $first = $repository->search(StaffUserCriteria::fromQuery(['page' => 1]));
        $second = $repository->search(StaffUserCriteria::fromQuery(['page' => 2]));

        self::assertSame(2, $first->pages());
        self::assertCount(20, $first->items);
        self::assertCount(1, $second->items);
        self::assertSame('user20@silpo.ua', $second->items[0]->email()->value);
    }
}
