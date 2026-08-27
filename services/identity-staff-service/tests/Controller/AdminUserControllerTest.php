<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminUserController;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\Identity\StaffUserCriteria;
use App\Http\ActorResolver;
use App\Http\JsonBody;
use App\Http\ProblemDetailsFactory;
use App\Http\StaffUserView;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP-контракт розділу «Користувачі» адмін-панелі (розділ 4.7).
 *
 * Тести доводять саме правила розмежування прав:
 *  - матриця 4.4: керувати staff-акаунтами можуть лише ролі з правом
 *    `users.manage.staff` — super_admin (✓) і network_manager (S*);
 *  - дерево 4.7 (RBAC-22/23): network_manager не створює super_admin,
 *    network_manager і analyst, а чужих акаунтів навіть не бачить (RBAC-18);
 *  - RBAC-04/27.1: рівно одна роль;
 *  - RBAC-13: порожній перелік магазинів = НУЛЬ доступу, і це видно у відповіді;
 *  - RBAC-24/25: собі роль не змінюють і себе не деактивують.
 */
#[CoversClass(AdminUserController::class)]
#[CoversClass(ActorResolver::class)]
#[CoversClass(JsonBody::class)]
#[CoversClass(StaffUserView::class)]
#[CoversClass(StaffUserCriteria::class)]
final class AdminUserControllerTest extends TestCase
{
    private const string PASSWORD = 'Rampa!Staff2026';

    private AuthContext $context;
    private AdminUserController $controller;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
        $this->controller = new AdminUserController(
            users: $this->context->userManagement,
            actors: new ActorResolver($this->context->users, $this->context->tokens),
            problems: new ProblemDetailsFactory(),
        );
    }

    // -----------------------------------------------------------------
    // Інфраструктура тесту
    // -----------------------------------------------------------------

    /**
     * Запит, який приходить від api-gateway: ідентичність — у службових
     * заголовках, які nginx виставляє після /internal/v1/auth/verify.
     *
     * @param array<string, mixed>|null $body
     */
    private function request(
        string $path,
        ?StaffUser $actor = null,
        ?array $body = null,
        string $method = 'GET',
    ): Request {
        $server = ['HTTP_X-Request-Id' => 'req-users-1'];

        if ($actor instanceof StaffUser) {
            $server['HTTP_X-User-Id'] = $actor->id();
            $server['HTTP_X-User-Role'] = $actor->role()->value;
            $server['HTTP_X-Contour'] = 'staff';
        }

        return Request::create(
            uri: $path,
            method: $method,
            server: $server,
            content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(StaffUser $actor, array $body): JsonResponse
    {
        return $this->controller->create(
            $this->request('/api/admin/v1/users', $actor, $body, 'POST'),
        );
    }

    private function superAdmin(string $email = 'root@silpo.ua'): StaffUser
    {
        return $this->context->createUser($email, Role::SuperAdmin, [], self::PASSWORD);
    }

    // -----------------------------------------------------------------
    // Список
    // -----------------------------------------------------------------

    public function testListReturnsAdminPageEnvelopeWithScopeFlags(): void
    {
        $actor = $this->superAdmin();
        $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);
        $this->context->createUser('so@silpo.ua', Role::StoreOperator, []);

        $response = $this->controller->list($this->request('/api/admin/v1/users', $actor));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(20, $body['perPage']);
        self::assertSame(1, $body['pages']);
        self::assertNull($body['emptyMessage']);
        self::assertSame('req-users-1', $response->headers->get('X-Request-Id'));

        // AUTH-61: жодного хеша пароля у відповіді
        self::assertStringNotContainsString('passwordHash', (string) $response->getContent());
        self::assertStringNotContainsString('argon2', (string) $response->getContent());
    }

    /**
     * UI-01: розміри сторінки — ті самі, що й у решті адмінських списків.
     */
    #[DataProvider('perPageProvider')]
    public function testPageSizeIsAcceptedOrRejected(int $perPage, int $expectedStatus): void
    {
        $actor = $this->superAdmin();

        $response = $this->controller->list(
            $this->request('/api/admin/v1/users?perPage='.$perPage, $actor),
        );

        self::assertSame($expectedStatus, $response->getStatusCode());

        if (422 === $expectedStatus) {
            self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
        } else {
            self::assertSame($perPage, $this->json($response)['perPage']);
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function perPageProvider(): array
    {
        return [
            '20' => [20, 200],
            '50' => [50, 200],
            '100' => [100, 200],
            '25 — не з переліку' => [25, 422],
            '0' => [0, 422],
        ];
    }

    public function testListPaginatesWithStablePageBoundaries(): void
    {
        $actor = $this->superAdmin();

        for ($i = 0; $i < 25; ++$i) {
            $this->context->createUser(\sprintf('operator%02d@silpo.ua', $i), Role::StoreOperator, ['A']);
        }

        $first = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?perPage=20&page=1', $actor),
        ));
        $second = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?perPage=20&page=2', $actor),
        ));

        self::assertSame(26, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertCount(20, $first['items']);
        self::assertCount(6, $second['items']);

        $firstIds = array_column($first['items'], 'id');
        $secondIds = array_column($second['items'], 'id');
        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function testListFiltersByRoleStatusAndSearch(): void
    {
        $actor = $this->superAdmin();
        $this->context->createUser('olena@silpo.ua', Role::StoreManager, ['A']);
        $this->context->createUser('petro@silpo.ua', Role::StoreOperator, ['A']);
        $fired = $this->context->createUser('fired@silpo.ua', Role::Analyst, []);
        $this->context->userManagement->deactivate($actor, $fired->id());

        $byRole = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?role=store_manager', $actor),
        ));
        self::assertSame(1, $byRole['total']);
        self::assertSame('olena@silpo.ua', $byRole['items'][0]['email']);

        $inactive = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?status=inactive', $actor),
        ));
        self::assertSame(1, $inactive['total']);
        self::assertSame('fired@silpo.ua', $inactive['items'][0]['email']);

        $active = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?status=active', $actor),
        ));
        self::assertSame(3, $active['total']);

        $byEmail = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?q=petro', $actor),
        ));
        self::assertSame(1, $byEmail['total']);

        // Пошук працює і за повним імʼям (AuthContext заводить «Тестовий Користувач»)
        $byName = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?q='.rawurlencode('Тестовий'), $actor),
        ));
        self::assertSame(4, $byName['total']);
    }

    public function testEmptySelectionCarriesExplanation(): void
    {
        $actor = $this->superAdmin();

        $body = $this->json($this->controller->list(
            $this->request('/api/admin/v1/users?q='.rawurlencode('немає-такого'), $actor),
        ));

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
        self::assertSame('Користувачів за заданими умовами не знайдено', $body['emptyMessage']);
    }

    public function testUnknownFilterValuesAreRejected(): void
    {
        $actor = $this->superAdmin();

        foreach (['role=wizard', 'role=supplier_admin', 'status=maybe'] as $query) {
            $response = $this->controller->list($this->request('/api/admin/v1/users?'.$query, $actor));

            self::assertSame(422, $response->getStatusCode(), $query);
            self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
        }
    }

    // -----------------------------------------------------------------
    // Права доступу до розділу
    // -----------------------------------------------------------------

    /**
     * Матриця 4.4: ролі без права `users.manage.staff` не мають доступу
     * до розділу взагалі — 403 на читання і на зміну.
     *
     * @return array<string, array{Role, list<string>}>
     */
    public static function forbiddenRoleProvider(): array
    {
        return [
            'store_manager' => [Role::StoreManager, ['A']],
            'store_operator' => [Role::StoreOperator, ['A']],
            'analyst' => [Role::Analyst, []],
        ];
    }

    /**
     * @param list<string> $storeIds
     */
    #[DataProvider('forbiddenRoleProvider')]
    public function testRolesWithoutPermissionGet403(Role $role, array $storeIds): void
    {
        $actor = $this->context->createUser('actor@silpo.ua', $role, $storeIds);
        $target = $this->context->createUser('target@silpo.ua', Role::StoreOperator, ['A']);

        $list = $this->controller->list($this->request('/api/admin/v1/users', $actor));
        self::assertSame(403, $list->getStatusCode());
        self::assertSame('RBAC_PERMISSION_DENIED', $this->json($list)['code']);

        $created = $this->create($actor, [
            'email' => 'new@silpo.ua',
            'fullName' => 'Новий Користувач',
            'role' => 'store_operator',
        ]);
        self::assertSame(403, $created->getStatusCode());
        self::assertSame('RBAC_PERMISSION_DENIED', $this->json($created)['code']);

        $card = $this->controller->get($target->id(), $this->request('/api/admin/v1/users/x', $actor));
        self::assertSame(403, $card->getStatusCode());

        $deactivated = $this->controller->deactivate(
            $target->id(),
            $this->request('/api/admin/v1/users/x/deactivate', $actor, [], 'POST'),
        );
        self::assertSame(403, $deactivated->getStatusCode());

        $reset = $this->controller->resetPassword(
            $target->id(),
            $this->request('/api/admin/v1/users/x/password/reset', $actor, [], 'POST'),
        );
        self::assertSame(403, $reset->getStatusCode());

        // Жодних змін стану
        self::assertTrue($this->context->users->findById($target->id())?->isActive());
        self::assertNull($this->context->users->findByEmail(
            \App\Domain\Identity\Email::fromString('new@silpo.ua'),
        ));
    }

    /**
     * RBAC-23 / сценарій 7 таблиці 4.10: network_manager не створює
     * super_admin — ані як роль, ані обхідним шляхом.
     */
    public function testNetworkManagerCannotCreateSuperAdmin(): void
    {
        $actor = $this->context->createUser('boss@silpo.ua', Role::NetworkManager);

        $response = $this->create($actor, [
            'email' => 'new-root@silpo.ua',
            'fullName' => 'Новий Root',
            'role' => 'super_admin',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('RBAC_ROLE_ASSIGNMENT_FORBIDDEN', $this->json($response)['code']);
        self::assertNull($this->context->users->findByEmail(
            \App\Domain\Identity\Email::fromString('new-root@silpo.ua'),
        ));
    }

    /**
     * Дерево 4.7 для network_manager — лише store_manager і store_operator.
     */
    public function testNetworkManagerCanOnlyCreateStoreRoles(): void
    {
        $actor = $this->context->createUser('boss@silpo.ua', Role::NetworkManager);

        $allowed = $this->create($actor, [
            'email' => 'receiver@silpo.ua',
            'fullName' => 'Приймальник Магазину',
            'role' => 'store_operator',
            'storeIds' => ['A'],
        ]);
        self::assertSame(201, $allowed->getStatusCode());
        self::assertSame('store_operator', $this->json($allowed)['role']);

        foreach (['analyst', 'network_manager'] as $role) {
            $denied = $this->create($actor, [
                'email' => $role.'@silpo.ua',
                'fullName' => 'Хтось',
                'role' => $role,
            ]);

            self::assertSame(403, $denied->getStatusCode(), $role);
            self::assertSame('RBAC_ROLE_ASSIGNMENT_FORBIDDEN', $this->json($denied)['code']);
        }
    }

    /**
     * RBAC-18: акаунти поза деревом призначення для network_manager
     * не існують — ані у списку, ані на картці.
     */
    public function testNetworkManagerSeesOnlyManageableAccounts(): void
    {
        $root = $this->superAdmin();
        $actor = $this->context->createUser('boss@silpo.ua', Role::NetworkManager);
        $manager = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);
        $this->context->createUser('analyst@silpo.ua', Role::Analyst);

        $list = $this->json($this->controller->list($this->request('/api/admin/v1/users', $actor)));

        self::assertSame(1, $list['total']);
        self::assertSame($manager->id(), $list['items'][0]['id']);

        $card = $this->controller->get($root->id(), $this->request('/api/admin/v1/users/x', $actor));
        self::assertSame(404, $card->getStatusCode());
        self::assertSame('RESOURCE_NOT_FOUND', $this->json($card)['code']);

        // …і змінити такий акаунт теж не можна
        $update = $this->controller->update(
            $root->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['role' => 'store_manager'], 'PATCH'),
        );
        self::assertSame(404, $update->getStatusCode());
        self::assertSame(Role::SuperAdmin, $this->context->users->findById($root->id())?->role());
    }

    // -----------------------------------------------------------------
    // Створення
    // -----------------------------------------------------------------

    /**
     * Пароль генерується і показується РІВНО ОДИН РАЗ.
     */
    public function testCreateGeneratesOneTimePassword(): void
    {
        $actor = $this->superAdmin();

        $response = $this->create($actor, [
            'email' => 'Olena@Silpo.UA',
            'fullName' => 'Олена Іванова',
            'role' => 'store_manager',
            'storeIds' => ['A', 'B'],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('olena@silpo.ua', $body['email']);
        self::assertSame('olena@silpo.ua', $body['login']);
        self::assertSame('store_manager', $body['role']);
        self::assertSame(['A', 'B'], $body['scope']['storeIds']);
        self::assertTrue($body['passwordGenerated']);
        self::assertSame('Запишіть пароль — повторно він не показується.', $body['passwordNotice']);

        $password = (string) $body['password'];
        self::assertGreaterThanOrEqual(12, mb_strlen($password));

        // Пароль справді робочий…
        $login = $this->context->authentication->login('olena@silpo.ua', $password);
        self::assertSame('olena@silpo.ua', $login->user->email()->value);

        // …і більше ніде не зʼявляється
        $card = $this->controller->get($body['id'], $this->request('/api/admin/v1/users/x', $actor));
        self::assertArrayNotHasKey('password', $this->json($card));
    }

    public function testCreateAcceptsExplicitPassword(): void
    {
        $actor = $this->superAdmin();

        $body = $this->json($this->create($actor, [
            'email' => 'ivan@silpo.ua',
            'fullName' => 'Іван Петренко',
            'role' => 'analyst',
            'password' => 'Rampa!Analyst2026',
        ]));

        self::assertFalse($body['passwordGenerated']);
        self::assertSame(
            'ivan@silpo.ua',
            $this->context->authentication->login('ivan@silpo.ua', 'Rampa!Analyst2026')->user->email()->value,
        );
    }

    /**
     * AUTH-13: використовується НАЯВНА політика паролів — з переліком правил.
     */
    public function testWeakExplicitPasswordIsRejected(): void
    {
        $actor = $this->superAdmin();

        $response = $this->create($actor, [
            'email' => 'weak@silpo.ua',
            'fullName' => 'Слабкий Пароль',
            'role' => 'analyst',
            'password' => 'qwerty',
        ]);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->json($response);
        self::assertSame('AUTH_WEAK_PASSWORD', $body['code']);
        self::assertNotEmpty($body['violations']);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $actor = $this->superAdmin();
        $this->context->createUser('taken@silpo.ua', Role::Analyst);

        $response = $this->create($actor, [
            'email' => 'TAKEN@silpo.ua',
            'fullName' => 'Дубль',
            'role' => 'analyst',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('USER_EMAIL_ALREADY_EXISTS', $this->json($response)['code']);
    }

    public function testMissingFieldsAreRejected(): void
    {
        $actor = $this->superAdmin();

        $noEmail = $this->create($actor, ['fullName' => 'Без Пошти', 'role' => 'analyst']);
        self::assertSame(422, $noEmail->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($noEmail)['code']);

        $noRole = $this->create($actor, ['email' => 'x@silpo.ua', 'fullName' => 'Без Ролі']);
        self::assertSame(422, $noRole->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($noRole)['code']);

        $badEmail = $this->create($actor, [
            'email' => 'не-пошта',
            'fullName' => 'Крива Пошта',
            'role' => 'analyst',
        ]);
        self::assertSame(422, $badEmail->getStatusCode());
    }

    /**
     * RBAC-27.2: staff-акаунт не отримує роль partner-контуру.
     */
    public function testPartnerRoleIsRejected(): void
    {
        $actor = $this->superAdmin();

        $response = $this->create($actor, [
            'email' => 'partner@silpo.ua',
            'fullName' => 'Чужий Контур',
            'role' => 'supplier_admin',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RBAC_CROSS_CONTOUR_ROLE_FORBIDDEN', $this->json($response)['code']);
    }

    // -----------------------------------------------------------------
    // RBAC-04 / RBAC-27.1: рівно одна роль
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function multipleRolesProvider(): array
    {
        return [
            'два різні значення в roles' => [['roles' => ['store_manager', 'store_operator']]],
            'два однакові значення в roles' => [['roles' => ['store_manager', 'store_manager']]],
            'role + roles' => [['role' => 'store_manager', 'roles' => ['store_operator']]],
            'порожній roles' => [['roles' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $roleFields
     */
    #[DataProvider('multipleRolesProvider')]
    public function testSecondRoleIsRejectedOnCreate(array $roleFields): void
    {
        $actor = $this->superAdmin();

        $response = $this->create($actor, [
            'email' => 'two-roles@silpo.ua',
            'fullName' => 'Дві Ролі',
            'storeIds' => ['A'],
        ] + $roleFields);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RBAC_MULTIPLE_ROLES_FORBIDDEN', $this->json($response)['code']);

        // RBAC-AC-07: жодних часткових змін
        self::assertNull($this->context->users->findByEmail(
            \App\Domain\Identity\Email::fromString('two-roles@silpo.ua'),
        ));
        self::assertSame([], $this->context->audit->all());
    }

    public function testSecondRoleIsRejectedOnUpdate(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A']);

        $response = $this->controller->update(
            $target->id(),
            $this->request(
                '/api/admin/v1/users/x',
                $actor,
                ['roles' => ['store_operator', 'store_manager'], 'fullName' => 'Нове Імʼя'],
                'PATCH',
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('RBAC_MULTIPLE_ROLES_FORBIDDEN', $this->json($response)['code']);

        // Ані роль, ані імʼя не змінилися
        $unchanged = $this->context->users->findById($target->id());
        self::assertSame(Role::StoreOperator, $unchanged?->role());
        self::assertSame('Тестовий Користувач', $unchanged?->fullName());
    }

    /**
     * Один елемент у `roles` — валідний спосіб призначити роль.
     */
    public function testSingleRoleInArrayIsAccepted(): void
    {
        $actor = $this->superAdmin();

        $response = $this->create($actor, [
            'email' => 'single@silpo.ua',
            'fullName' => 'Одна Роль',
            'roles' => ['store_manager'],
            'storeIds' => ['A'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('store_manager', $this->json($response)['role']);
    }

    // -----------------------------------------------------------------
    // RBAC-13: порожній перелік магазинів = нуль доступу
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{string, list<string>, bool}>
     */
    public static function zeroAccessProvider(): array
    {
        return [
            'store_manager без магазинів' => ['store_manager', [], true],
            'store_operator без магазинів' => ['store_operator', [], true],
            'store_manager з магазином' => ['store_manager', ['A'], false],
            'analyst без магазинів — вся мережа' => ['analyst', [], false],
            'network_manager без магазинів — вся мережа' => ['network_manager', [], false],
        ];
    }

    /**
     * @param list<string> $storeIds
     */
    #[DataProvider('zeroAccessProvider')]
    public function testZeroAccessIsExposedAsSeparateFlag(
        string $role,
        array $storeIds,
        bool $expectedZeroAccess,
    ): void {
        $actor = $this->superAdmin();

        $body = $this->json($this->create($actor, [
            'email' => $role.'-scope@silpo.ua',
            'fullName' => 'Перевірка Скоупа',
            'role' => $role,
            'storeIds' => $storeIds,
        ]));

        self::assertSame($expectedZeroAccess, $body['scope']['zeroAccess']);
        self::assertSame(\in_array($role, ['store_manager', 'store_operator'], true), $body['scope']['storeScoped']);
        self::assertSame(!\in_array($role, ['store_manager', 'store_operator'], true), $body['scope']['networkWide']);

        if ($expectedZeroAccess) {
            self::assertSame(StaffUserView::ZERO_ACCESS_WARNING, $body['scope']['warning']);
        } else {
            self::assertNull($body['scope']['warning']);
        }
    }

    /**
     * Ознака нульового доступу зʼявляється і після того, як магазини
     * зняли з уже наявного акаунта.
     */
    public function testZeroAccessAppearsAfterScopeIsCleared(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);

        $body = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['storeIds' => []], 'PATCH'),
        ));

        self::assertSame([], $body['scope']['storeIds']);
        self::assertTrue($body['scope']['zeroAccess']);
        self::assertSame(StaffUserView::ZERO_ACCESS_WARNING, $body['scope']['warning']);
    }

    // -----------------------------------------------------------------
    // Редагування
    // -----------------------------------------------------------------

    public function testUpdateAppliesOnlyPassedFields(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A']);

        $renamed = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['fullName' => 'Олена Іванова'], 'PATCH'),
        ));
        self::assertSame('Олена Іванова', $renamed['fullName']);
        self::assertSame('store_manager', $renamed['role']);
        self::assertSame(['A'], $renamed['scope']['storeIds']);

        $rescoped = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['storeIds' => ['A', 'B']], 'PATCH'),
        ));
        self::assertSame(['A', 'B'], $rescoped['scope']['storeIds']);
        self::assertSame('Олена Іванова', $rescoped['fullName']);

        $promoted = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['role' => 'analyst'], 'PATCH'),
        ));
        self::assertSame('analyst', $promoted['role']);
    }

    /**
     * RBAC-16: підвищення магазинної ролі до мережевої прибирає скоуп —
     * інакше він лежав би в базі мертвим і «ожив» би при пониженні назад.
     */
    public function testPromotionToNetworkRoleClearsLeftoverScope(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('sm@silpo.ua', Role::StoreManager, ['A', 'B']);

        $promoted = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['role' => 'analyst'], 'PATCH'),
        ));

        self::assertSame('analyst', $promoted['role']);
        self::assertSame([], $promoted['scope']['storeIds']);
        self::assertTrue($promoted['scope']['networkWide']);
        self::assertFalse($promoted['scope']['zeroAccess']);
        self::assertSame([], $this->context->users->findById($target->id())?->storeIds());
    }

    /**
     * Пониження мережевої ролі до магазинної НЕ вигадує скоуп: користувач
     * лишається з нулем доступу, поки магазини не привʼязали явно (RBAC-13).
     */
    public function testDemotionToStoreRoleLeavesZeroAccess(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('analyst@silpo.ua', Role::Analyst);

        $demoted = $this->json($this->controller->update(
            $target->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['role' => 'store_operator'], 'PATCH'),
        ));

        self::assertSame('store_operator', $demoted['role']);
        self::assertSame([], $demoted['scope']['storeIds']);
        self::assertTrue($demoted['scope']['zeroAccess']);
        self::assertSame(StaffUserView::ZERO_ACCESS_WARNING, $demoted['scope']['warning']);
    }

    /**
     * RBAC-24 / сценарій 8: власну роль не змінюють, себе не деактивують.
     */
    public function testActorCannotDemoteOrDeactivateSelf(): void
    {
        $actor = $this->superAdmin();
        // Резервний super_admin, щоб спрацював саме RBAC-24, а не RBAC-25
        $this->superAdmin('root-backup@silpo.ua');

        $demote = $this->controller->update(
            $actor->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['role' => 'analyst'], 'PATCH'),
        );
        self::assertSame(403, $demote->getStatusCode());
        self::assertSame('RBAC_SELF_ROLE_CHANGE_FORBIDDEN', $this->json($demote)['code']);

        $rescope = $this->controller->update(
            $actor->id(),
            $this->request('/api/admin/v1/users/x', $actor, ['storeIds' => ['A']], 'PATCH'),
        );
        self::assertSame(403, $rescope->getStatusCode());

        $deactivate = $this->controller->deactivate(
            $actor->id(),
            $this->request('/api/admin/v1/users/x/deactivate', $actor, [], 'POST'),
        );
        self::assertSame(403, $deactivate->getStatusCode());
        self::assertSame('RBAC_SELF_ROLE_CHANGE_FORBIDDEN', $this->json($deactivate)['code']);

        $current = $this->context->users->findById($actor->id());
        self::assertSame(Role::SuperAdmin, $current?->role());
        self::assertTrue($current?->isActive());
    }

    /**
     * RBAC-25 / сценарій 10: останній активний super_admin — 409.
     */
    public function testLastSuperAdminCannotBeDeactivated(): void
    {
        $actor = $this->superAdmin();

        $response = $this->controller->deactivate(
            $actor->id(),
            $this->request('/api/admin/v1/users/x/deactivate', $actor, [], 'POST'),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('RBAC_LAST_SUPER_ADMIN', $this->json($response)['code']);
    }

    /**
     * AUTH-12/RBAC-26: деактивація блокує вхід і гасить активні сесії.
     */
    public function testDeactivateBlocksLoginAndActivateRestoresIt(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A'], self::PASSWORD);

        $this->context->authentication->login('operator@silpo.ua', self::PASSWORD);
        self::assertCount(
            1,
            $this->context->refreshTokens->findActiveForUser($target->id(), $this->context->clock->now()),
        );

        $deactivated = $this->controller->deactivate(
            $target->id(),
            $this->request('/api/admin/v1/users/x/deactivate', $actor, [], 'POST'),
        );
        self::assertSame(200, $deactivated->getStatusCode());
        self::assertFalse($this->json($deactivated)['active']);
        self::assertSame(
            [],
            $this->context->refreshTokens->findActiveForUser($target->id(), $this->context->clock->now()),
        );

        $activated = $this->controller->activate(
            $target->id(),
            $this->request('/api/admin/v1/users/x/activate', $actor, [], 'POST'),
        );
        self::assertSame(200, $activated->getStatusCode());
        self::assertTrue($this->json($activated)['active']);
    }

    // -----------------------------------------------------------------
    // Скидання пароля
    // -----------------------------------------------------------------

    public function testPasswordResetIssuesOneTimePasswordAndKillsSessions(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A'], self::PASSWORD);

        $this->context->authentication->login('operator@silpo.ua', self::PASSWORD);

        $response = $this->controller->resetPassword(
            $target->id(),
            $this->request('/api/admin/v1/users/x/password/reset', $actor, [], 'POST'),
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);
        self::assertTrue($body['passwordGenerated']);
        self::assertSame('Запишіть пароль — повторно він не показується.', $body['passwordNotice']);

        $newPassword = (string) $body['password'];
        self::assertNotSame(self::PASSWORD, $newPassword);

        // AUTH-32: сесії власника погашені
        self::assertSame(
            [],
            $this->context->refreshTokens->findActiveForUser($target->id(), $this->context->clock->now()),
        );

        // Старий пароль більше не працює, новий — працює
        try {
            $this->context->authentication->login('operator@silpo.ua', self::PASSWORD);
            self::fail('Старий пароль мав перестати діяти.');
        } catch (\App\Domain\Auth\Exception\InvalidCredentialsException) {
            // очікувано
        }

        $login = $this->context->authentication->login('operator@silpo.ua', $newPassword);
        self::assertSame($target->id(), $login->user->id());
    }

    /**
     * AUTH-13: скидання на явний пароль теж проходить політику,
     * включно з історією останніх пʼяти хешів.
     */
    public function testPasswordResetRejectsWeakAndRepeatedPassword(): void
    {
        $actor = $this->superAdmin();
        $target = $this->context->createUser('operator@silpo.ua', Role::StoreOperator, ['A'], self::PASSWORD);

        $weak = $this->controller->resetPassword(
            $target->id(),
            $this->request('/api/admin/v1/users/x/password/reset', $actor, ['password' => 'qwerty'], 'POST'),
        );
        self::assertSame(422, $weak->getStatusCode());
        self::assertSame('AUTH_WEAK_PASSWORD', $this->json($weak)['code']);

        $repeated = $this->controller->resetPassword(
            $target->id(),
            $this->request(
                '/api/admin/v1/users/x/password/reset',
                $actor,
                ['password' => self::PASSWORD],
                'POST',
            ),
        );
        self::assertSame(422, $repeated->getStatusCode());
        self::assertSame('AUTH_WEAK_PASSWORD', $this->json($repeated)['code']);
    }

    // -----------------------------------------------------------------
    // Ідентичність запиту
    // -----------------------------------------------------------------

    /**
     * Без ідентичності від шлюзу і без токена — 401, а не 403:
     * запит без ідентичності не має відрізнятися від запиту з чужою.
     */
    public function testRequestWithoutIdentityIsRejected(): void
    {
        $response = $this->controller->list($this->request('/api/admin/v1/users'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTH_TOKEN_INVALID', $this->json($response)['code']);
    }

    public function testForgedIdentityHeadersAreRejected(): void
    {
        $actor = $this->superAdmin();

        // Роль partner-контуру в заголовку staff-маршруту
        $request = $this->request('/api/admin/v1/users', $actor);
        $request->headers->set('X-User-Role', 'supplier_admin');
        self::assertSame(401, $this->controller->list($request)->getStatusCode());

        // Контур не збігається
        $wrongContour = $this->request('/api/admin/v1/users', $actor);
        $wrongContour->headers->set('X-Contour', 'partner');
        self::assertSame(401, $this->controller->list($wrongContour)->getStatusCode());
    }

    /**
     * AUTH-12: акаунт, деактивований уже після перевірки токена шлюзом,
     * не працює навіть із коректними заголовками.
     */
    public function testDeactivatedActorIsRejected(): void
    {
        $root = $this->superAdmin();
        $actor = $this->superAdmin('second@silpo.ua');
        $this->context->userManagement->deactivate($root, $actor->id());

        $response = $this->controller->list($this->request('/api/admin/v1/users', $actor));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTH_TOKEN_INVALID', $this->json($response)['code']);
    }

    /**
     * Запасний шлях без шлюзу: власний access-токен staff-контуру.
     */
    public function testBearerTokenIsAcceptedWhenGatewayHeadersAreAbsent(): void
    {
        $actor = $this->superAdmin();
        $token = $this->context->tokens->issueFor($actor)->accessToken;

        $request = Request::create(
            uri: '/api/admin/v1/users',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token],
        );

        $response = $this->controller->list($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['total']);
    }

    /**
     * AUTH-02: токен partner-контуру на staff-маршруті — 401.
     */
    public function testPartnerTokenIsRejected(): void
    {
        $request = Request::create(
            uri: '/api/admin/v1/users',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->context->partnerAccessToken()],
        );

        $response = $this->controller->list($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTH_TOKEN_INVALID', $this->json($response)['code']);
    }

    public function testMalformedJsonBodyReturns422(): void
    {
        $actor = $this->superAdmin();

        $request = Request::create(
            uri: '/api/admin/v1/users',
            method: 'POST',
            server: [
                'HTTP_X-User-Id' => $actor->id(),
                'HTTP_X-User-Role' => $actor->role()->value,
                'HTTP_X-Contour' => 'staff',
            ],
            content: '{не json}',
        );

        $response = $this->controller->create($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
    }

    /**
     * RBAC-29: кожна зміна лишає слід в журналі аудиту.
     */
    public function testEveryChangeIsAudited(): void
    {
        $actor = $this->superAdmin();

        $created = $this->json($this->create($actor, [
            'email' => 'audited@silpo.ua',
            'fullName' => 'Під Аудитом',
            'role' => 'store_operator',
            'storeIds' => ['A'],
        ]));
        $id = (string) $created['id'];

        $this->controller->update(
            $id,
            $this->request('/api/admin/v1/users/x', $actor, ['fullName' => 'Нове Імʼя'], 'PATCH'),
        );
        $this->controller->update(
            $id,
            $this->request('/api/admin/v1/users/x', $actor, ['storeIds' => ['A', 'B']], 'PATCH'),
        );
        $reset = $this->json($this->controller->resetPassword(
            $id,
            $this->request('/api/admin/v1/users/x/password/reset', $actor, [], 'POST'),
        ));
        $this->controller->deactivate(
            $id,
            $this->request('/api/admin/v1/users/x/deactivate', $actor, [], 'POST'),
        );

        $entries = $this->context->audit->findByTarget($id);
        $actions = array_map(static fn ($entry): string => $entry->action->value, $entries);

        self::assertSame(
            ['create', 'rename', 'scope_change', 'password_reset', 'deactivate'],
            $actions,
        );

        // RBAC-29: у записі є актор і requestId, AUTH-61: пароля немає
        $journal = json_encode(
            array_map(static fn ($entry): array => $entry->toArray(), $entries),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        );
        self::assertStringContainsString($actor->id(), $journal);
        self::assertStringContainsString('req-users-1', $journal);
        self::assertStringNotContainsString((string) $reset['password'], $journal);
    }
}
