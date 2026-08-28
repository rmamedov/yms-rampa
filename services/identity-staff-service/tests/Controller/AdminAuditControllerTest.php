<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminAuditController;
use App\Domain\Identity\Email;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUser;
use App\Domain\UserManagement\AuditLogService;
use App\Http\ActorResolver;
use App\Http\ProblemDetailsFactory;
use App\Tests\Support\AuthContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * RBAC-29/RBAC-31: журнал аудиту доступний для читання ролям із правом
 * `audit.read`.
 *
 * Записи в `role_audit` велися від початку, але назовні не публікувалися:
 * super_admin мав право audit.read і жодного способу ним скористатися.
 */
#[CoversClass(AdminAuditController::class)]
#[CoversClass(AuditLogService::class)]
final class AdminAuditControllerTest extends TestCase
{
    private const string PASSWORD = 'Rampa!Staff2026';

    private AuthContext $context;
    private AdminAuditController $controller;

    protected function setUp(): void
    {
        $this->context = new AuthContext();
        $this->controller = new AdminAuditController(
            audit: new AuditLogService(
                $this->context->audit,
                $this->context->users,
                $this->context->accessDecider,
            ),
            actors: new ActorResolver($this->context->users, $this->context->tokens),
            problems: new ProblemDetailsFactory(),
        );
    }

    public function testJournalShowsWhoChangedWhomAndWhen(): void
    {
        $actor = $this->superAdmin();
        $created = $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('sm@silpo.ua'),
            plainPassword: self::PASSWORD,
            role: Role::StoreManager,
            storeIds: ['store-1'],
            fullName: 'Марія Магазинна',
        );
        $this->context->userManagement->deactivate($actor, $created->id());

        $body = $this->json($this->controller->list($this->request($actor)));

        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['items']);

        // Від новіших до старіших.
        self::assertSame('deactivate', $body['items'][0]['action']);
        self::assertSame('create', $body['items'][1]['action']);

        // Замість UUID — впізнавані підписи.
        self::assertSame('Деактивація', $body['items'][0]['actionLabel']);
        self::assertSame('Марія Магазинна', $body['items'][0]['targetName']);
        self::assertSame($actor->fullName(), $body['items'][0]['actorName']);
        self::assertSame($actor->id(), $body['items'][0]['actorUserId']);
        self::assertNotSame('', (string) $body['items'][0]['timestamp']);
    }

    public function testFilterByActionNarrowsJournal(): void
    {
        $actor = $this->superAdmin();
        $created = $this->context->userManagement->createUser(
            actor: $actor,
            email: Email::fromString('sm@silpo.ua'),
            plainPassword: self::PASSWORD,
            role: Role::StoreManager,
            storeIds: ['store-1'],
            fullName: 'Марія Магазинна',
        );
        $this->context->userManagement->deactivate($actor, $created->id());

        $body = $this->json($this->controller->list($this->request($actor, ['action' => 'create'])));

        self::assertSame(1, $body['total']);
        self::assertSame('create', $body['items'][0]['action']);
    }

    public function testUnknownActionIsRejected(): void
    {
        $response = $this->controller->list($this->request($this->superAdmin(), ['action' => 'вигадана']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->json($response)['code']);
    }

    /** Матриця 4.4: без audit.read журнал недоступний. */
    #[DataProvider('rolesWithoutAuditRead')]
    public function testRolesWithoutPermissionAreDenied(Role $role): void
    {
        $actor = $this->context->createUser('denied@silpo.ua', $role, ['store-1'], self::PASSWORD);

        $response = $this->controller->list($this->request($actor));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('RBAC_PERMISSION_DENIED', $this->json($response)['code']);
    }

    /** @return iterable<string, array{Role}> */
    public static function rolesWithoutAuditRead(): iterable
    {
        yield 'store_manager' => [Role::StoreManager];
        yield 'store_operator' => [Role::StoreOperator];
        yield 'analyst' => [Role::Analyst];
    }

    public function testNetworkManagerHasAuditRead(): void
    {
        $actor = $this->context->createUser('nm@silpo.ua', Role::NetworkManager, [], self::PASSWORD);

        self::assertSame(200, $this->controller->list($this->request($actor))->getStatusCode());
    }

    private function superAdmin(string $email = 'root@silpo.ua'): StaffUser
    {
        return $this->context->createUser($email, Role::SuperAdmin, [], self::PASSWORD);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(StaffUser $actor, array $query = []): Request
    {
        return Request::create(
            uri: '/api/admin/v1/audit',
            method: 'GET',
            parameters: $query,
            server: [
                'HTTP_X-Request-Id' => 'req-audit-1',
                'HTTP_X-User-Id' => $actor->id(),
                'HTTP_X-User-Role' => $actor->role()->value,
                'HTTP_X-Contour' => 'staff',
            ],
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
}
