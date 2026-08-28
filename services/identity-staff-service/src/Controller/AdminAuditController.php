<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Identity\Exception\ValidationException;
use App\Domain\Shared\DomainException;
use App\Domain\UserManagement\AuditLogService;
use App\Domain\UserManagement\RoleAuditAction;
use App\Http\ActorResolver;
use App\Http\ProblemDetailsFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розділ «Журнал аудиту» адмін-панелі (RBAC-29, RBAC-31).
 *
 * Доступ мають лише ролі з правом `audit.read` за матрицею 4.4 —
 * super_admin і network_manager; решті 403 RBAC_PERMISSION_DENIED.
 * Право перевіряє AuditLogService, тому контролер матрицю не дублює.
 *
 * ОБСЯГ: журнал покриває зміни облікових записів і ролей — рівно те, що
 * пише `role_audit`. Дії над магазинами, постачальниками й бронюваннями
 * ведуть інші сервіси у власних журналах і сюди не потрапляють.
 */
#[Route('/api/admin/v1/audit')]
final readonly class AdminAuditController
{
    public function __construct(
        private AuditLogService $audit,
        private ActorResolver $actors,
        private ProblemDetailsFactory $problems,
    ) {
    }

    /** Журнал із серверною пагінацією і фільтрами за дією та користувачем. */
    #[Route('', name: 'admin_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $actor = $this->actors->staff($request);

            $actionRaw = trim((string) $request->query->get('action', ''));
            $action = '' === $actionRaw ? null : RoleAuditAction::tryFrom($actionRaw);

            if ('' !== $actionRaw && !$action instanceof RoleAuditAction) {
                throw new ValidationException(
                    \sprintf('Невідома дія журналу «%s».', $actionRaw),
                );
            }

            $targetUserId = trim((string) $request->query->get('targetUserId', ''));

            $payload = $this->audit->page(
                actor: $actor,
                page: $request->query->getInt('page', 1),
                perPage: $request->query->getInt('perPage', AuditLogService::DEFAULT_PER_PAGE),
                targetUserId: '' === $targetUserId ? null : $targetUserId,
                action: $action,
            );

            $response = new JsonResponse($payload, Response::HTTP_OK);
            $response->headers->set(
                ProblemDetailsFactory::REQUEST_ID_HEADER,
                ProblemDetailsFactory::requestId($request),
            );

            return $response;
        } catch (DomainException $exception) {
            return $this->problems->fromDomainException($exception, $request);
        }
    }
}
