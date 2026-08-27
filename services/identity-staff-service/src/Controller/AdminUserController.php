<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Identity\Contour;
use App\Domain\Identity\Email;
use App\Domain\Identity\Exception\CrossContourRoleException;
use App\Domain\Identity\Exception\MultipleRolesForbiddenException;
use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Identity\Role;
use App\Domain\Identity\StaffUserCriteria;
use App\Domain\Shared\DomainException;
use App\Domain\UserManagement\StaffUserService;
use App\Http\ActorResolver;
use App\Http\JsonBody;
use App\Http\ProblemDetailsFactory;
use App\Http\StaffUserView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розділ «Користувачі» адмін-панелі (розділ 4.7) — контур адміністратора.
 *
 * До цих маршрутів доступ мають лише ролі з правом `users.manage.staff`
 * за матрицею 4.4: super_admin (✓) і network_manager (S* — лише в межах
 * дерева призначення 4.7, тобто store_manager і store_operator). Решті
 * ролей — 403 RBAC_PERMISSION_DENIED; перевіряє це AccessDecider усередині
 * StaffUserService, тому контролер не дублює матрицю.
 *
 * Ключові інваріанти, які видно саме тут:
 *  - RBAC-04/RBAC-27.1: рівно ОДНА роль. Спроба передати другу (полем
 *    `roles` або `role` + `roles`) — 422 RBAC_MULTIPLE_ROLES_FORBIDDEN
 *    ще до будь-якої зміни стану;
 *  - RBAC-13: порожній перелік магазинів для магазинних ролей — це НУЛЬ
 *    доступу; ознака `scope.zeroAccess` у відповіді дозволяє інтерфейсу
 *    попередити адміністратора (див. StaffUserView::scope);
 *  - AUTH-61: пароль існує лише у відповіді на створення й на скидання,
 *    рівно один раз; у сховищі — тільки argon2id-хеш.
 *
 * Усі помилки — RFC 7807 `application/problem+json` з `code` і `requestId`
 * (RBAC-33).
 */
#[Route('/api/admin/v1/users')]
final readonly class AdminUserController
{
    public function __construct(
        private StaffUserService $users,
        private ActorResolver $actors,
        private ProblemDetailsFactory $problems,
    ) {
    }

    /**
     * Список із серверними фільтрами (роль, статус), пошуком за email/імʼям
     * і пагінацією 20/50/100 (UI-01).
     */
    #[Route('', name: 'admin_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $actor = $this->actors->staff($request);

            /** @var array<string, mixed> $query */
            $query = $request->query->all();

            return new JsonResponse(StaffUserView::page(
                $this->users->listUsers($actor, StaffUserCriteria::fromQuery($query)),
            ));
        });
    }

    /**
     * Створення облікового запису.
     *
     * Пароль або задає адміністратор, або (якщо поля немає) його генерує
     * сервіс — і показує РІВНО ОДИН РАЗ, як зроблено для водіїв у
     * partner-service (SUP-DRV-03).
     */
    #[Route('', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($request): JsonResponse {
            $actor = $this->actors->staff($request);
            $body = JsonBody::fromRequest($request);

            $role = self::requiredRole($body);
            $password = $body->optionalRaw('password');

            $credentials = $this->users->createUserWithCredentials(
                actor: $actor,
                email: Email::fromString($body->requiredString('email')),
                plainPassword: $password,
                role: $role,
                storeIds: self::storeIds($body, $role),
                fullName: $body->requiredString('fullName'),
                requestId: ProblemDetailsFactory::requestId($request),
                ip: $request->getClientIp(),
            );

            return $this->respond(
                StaffUserView::credentials($credentials, null === $password),
                $request,
                Response::HTTP_CREATED,
            );
        });
    }

    #[Route('/{id}', name: 'admin_users_get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($id, $request): JsonResponse {
            $actor = $this->actors->staff($request);

            return $this->respond(StaffUserView::user($this->users->getUser($actor, $id)), $request);
        });
    }

    /**
     * Часткове оновлення: застосовуються ЛИШЕ передані поля.
     *
     * Порядок навмисний — спершу роль, потім скоуп: при переведенні
     * мережевої ролі в магазинну перелік магазинів має лягти вже на нову роль.
     */
    #[Route('/{id}', name: 'admin_users_update', methods: ['PATCH', 'PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($id, $request): JsonResponse {
            $actor = $this->actors->staff($request);
            $body = JsonBody::fromRequest($request);

            $requestId = ProblemDetailsFactory::requestId($request);
            $ip = $request->getClientIp();

            // RBAC-27.1: перелік ролей розбирається ПЕРШИМ — друга роль
            // має відхилити запит до будь-яких змін.
            $role = self::optionalRole($body);
            $storeIds = $body->optionalStringList('storeIds');
            $fullName = $body->has('fullName') ? $body->requiredString('fullName') : null;

            // RBAC-18: акаунт, яким актор не має права керувати, для нього
            // не існує — 404 ще до спроби щось змінити.
            $user = $this->users->getUser($actor, $id);

            if (null !== $role && $role !== $user->role()) {
                $user = $this->users->assignRole($actor, $id, $role, $requestId, $ip);
            }

            if (null !== $storeIds) {
                $user = $this->users->changeScope($actor, $id, $storeIds, $requestId, $ip);
            }

            if (null !== $fullName) {
                $user = $this->users->rename($actor, $id, $fullName, $requestId, $ip);
            }

            return $this->respond(StaffUserView::user($user), $request);
        });
    }

    /**
     * AUTH-12/RBAC-26: вхід блокується, активні сесії гасяться негайно.
     * RBAC-24: деактивувати себе не можна; RBAC-25: останнього активного
     * super_admin — теж (409).
     */
    #[Route('/{id}/deactivate', name: 'admin_users_deactivate', methods: ['POST'])]
    public function deactivate(string $id, Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($id, $request): JsonResponse {
            $actor = $this->actors->staff($request);
            $this->users->getUser($actor, $id);

            return $this->respond(StaffUserView::user($this->users->deactivate(
                $actor,
                $id,
                ProblemDetailsFactory::requestId($request),
                $request->getClientIp(),
            )), $request);
        });
    }

    #[Route('/{id}/activate', name: 'admin_users_activate', methods: ['POST'])]
    public function activate(string $id, Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($id, $request): JsonResponse {
            $actor = $this->actors->staff($request);
            $this->users->getUser($actor, $id);

            return $this->respond(StaffUserView::user($this->users->reactivate(
                $actor,
                $id,
                ProblemDetailsFactory::requestId($request),
                $request->getClientIp(),
            )), $request);
        });
    }

    /**
     * Перегенерація пароля з одноразовим показом (SUP-DRV-04 для водіїв —
     * тут те саме для співробітників). Старий пароль інвалідовується,
     * усі сесії власника гасяться (AUTH-32).
     */
    #[Route('/{id}/password/reset', name: 'admin_users_password_reset', methods: ['POST'])]
    public function resetPassword(string $id, Request $request): JsonResponse
    {
        return $this->guard($request, function () use ($id, $request): JsonResponse {
            $actor = $this->actors->staff($request);
            $this->users->getUser($actor, $id);

            $body = JsonBody::fromRequest($request);
            $password = $body->optionalRaw('password');

            $credentials = $this->users->resetPassword(
                actor: $actor,
                targetUserId: $id,
                plainPassword: $password,
                requestId: ProblemDetailsFactory::requestId($request),
                ip: $request->getClientIp(),
            );

            return $this->respond(
                StaffUserView::credentials($credentials, null === $password),
                $request,
            );
        });
    }

    /**
     * RBAC-04 / RBAC-27.1: рівно одна роль.
     *
     * Приймається і `role: "store_manager"`, і `roles: ["store_manager"]` —
     * але саме ОДНЕ значення. Два (зокрема однакові, передані обома полями)
     * означають спробу дати другу роль: 422 RBAC_MULTIPLE_ROLES_FORBIDDEN.
     */
    private static function optionalRole(JsonBody $body): ?Role
    {
        $candidates = [];

        if ($body->has('role')) {
            $candidates[] = (string) $body->optionalString('role');
        }

        foreach ($body->optionalStringList('roles') ?? [] as $value) {
            $candidates[] = $value;
        }

        if ([] === $candidates && !$body->has('roles')) {
            return null;
        }

        if (1 !== \count($candidates)) {
            throw new MultipleRolesForbiddenException(array_values($candidates));
        }

        return self::role($candidates[0]);
    }

    private static function requiredRole(JsonBody $body): Role
    {
        $role = self::optionalRole($body);

        if (!$role instanceof Role) {
            throw new ValidationException(
                'Поле "role" обовʼязкове.',
                ['Не вказано роль користувача'],
            );
        }

        return $role;
    }

    private static function role(string $value): Role
    {
        $role = Role::tryFrom($value);

        if (!$role instanceof Role) {
            throw new ValidationException(
                'Невідома роль.',
                [\sprintf(
                    'Невідома роль «%s»; допустимі: %s',
                    $value,
                    implode(', ', array_map(static fn (Role $r): string => $r->value, Role::staffRoles())),
                )],
            );
        }

        // RBAC-27.2: акаунт співробітника не може отримати роль partner-контуру.
        if (Contour::Staff !== $role->contour()) {
            throw new CrossContourRoleException($role);
        }

        return $role;
    }

    /**
     * RBAC-13: перелік магазинів має сенс лише для магазинних ролей;
     * мережевим ролям (RBAC-16) він не потрібен і мовчки ігнорується,
     * щоб у базі не лишалося скоупа, який ні на що не впливає.
     *
     * @return list<string>
     */
    private static function storeIds(JsonBody $body, Role $role): array
    {
        if (!$role->isStoreScoped()) {
            return [];
        }

        return $body->optionalStringList('storeIds') ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(
        array $payload,
        Request $request,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        $response = new JsonResponse($payload, $status);
        $response->headers->set(
            ProblemDetailsFactory::REQUEST_ID_HEADER,
            ProblemDetailsFactory::requestId($request),
        );

        return $response;
    }

    /**
     * Єдина точка перетворення доменних помилок у RFC 7807 (RBAC-33).
     *
     * @param \Closure(): JsonResponse $action
     */
    private function guard(Request $request, \Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $exception) {
            return $this->problems->fromDomainException($exception, $request);
        }
    }
}
