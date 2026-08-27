<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mongo;

use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUserCriteria;
use App\Infrastructure\Mongo\MongoStaffUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Предикат запиту до `staff_users` для списку користувачів (4.7).
 *
 * Тест НЕ потребує ані ext-mongodb, ані сервера: перевіряється саме форма
 * фільтра, бо RBAC-17 вимагає, щоб фільтрація виконувалася запитом, а не
 * в памʼяті — а це видно лише з фільтра.
 */
#[CoversClass(MongoStaffUserRepository::class)]
final class MongoStaffUserQueryTest extends TestCase
{
    public function testRoleFilterIsAlwaysAnExplicitPredicate(): void
    {
        $filter = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery([]));

        // Навіть без фільтра в інтерфейсі вибірка обмежена staff-ролями:
        // partner-акаунти живуть в іншому контурі (RBAC-09).
        self::assertSame(
            ['super_admin', 'network_manager', 'store_manager', 'store_operator', 'analyst'],
            $filter['role']['$in'],
        );
        self::assertArrayNotHasKey('active', $filter);
        self::assertArrayNotHasKey('$or', $filter);
    }

    /**
     * RBAC-23: порожній перелік дозволених ролей має давати гарантовано
     * порожню вибірку (`$in: []`), а НЕ відсутність фільтра.
     */
    public function testEmptyManageableRolesProduceEmptySelection(): void
    {
        $filter = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery([])->restrictedTo([]));

        self::assertSame([], $filter['role']['$in']);
    }

    public function testStatusFilterCoversArchivedDocuments(): void
    {
        $active = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery(['status' => 'active']));
        self::assertTrue($active['active']);
        // DATA-03: архівований запис активним не вважається
        self::assertNull($active['archivedAt']);

        $inactive = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery(['status' => 'inactive']));
        self::assertFalse($inactive['active']);
        self::assertArrayNotHasKey('archivedAt', $inactive);
    }

    public function testSearchLooksIntoEmailAndFullName(): void
    {
        $filter = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery(['q' => 'olena']));

        self::assertSame(
            [
                ['email' => ['$regex' => 'olena', '$options' => 'i']],
                ['fullName' => ['$regex' => 'olena', '$options' => 'i']],
            ],
            $filter['$or'],
        );
    }

    /**
     * Пошуковий рядок іде в регулярний вираз, тому спецсимволи мають бути
     * екрановані — інакше «.*» з поля пошуку вибрав би всю колекцію.
     */
    public function testSearchTermIsEscapedBeforeItBecomesRegex(): void
    {
        $filter = MongoStaffUserRepository::filterOf(StaffUserCriteria::fromQuery(['q' => '.*']));

        self::assertSame('\.\*', $filter['$or'][0]['email']['$regex']);
    }

    public function testRoleFilterNarrowsToOneRole(): void
    {
        $filter = MongoStaffUserRepository::filterOf(
            StaffUserCriteria::fromQuery(['role' => 'store_operator'])
                ->restrictedTo([Role::StoreManager, Role::StoreOperator]),
        );

        self::assertSame(['store_operator'], $filter['role']['$in']);
    }
}
