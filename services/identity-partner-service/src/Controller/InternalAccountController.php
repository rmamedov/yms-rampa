<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Account\PartnerRole;
use App\Domain\Auth\SessionService;
use App\Domain\Exception\ValidationException;
use App\Domain\Provisioning\CreatePartnerAccount;
use App\Domain\Provisioning\PartnerAccountProvisioner;
use App\Infrastructure\Http\JsonRequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Внутрішній API для partner-service (AUTH-23, AUTH-25, AUTH-28, DATA-35).
 *
 * Ці ендпоїнти не публікуються через api-gateway: вони доступні лише в межах
 * кластера (mesh/NetworkPolicy) і викликаються partner-service синхронно або
 * як альтернатива RabbitMQ-команді.
 */
#[Route('/internal/v1/partner-accounts')]
final readonly class InternalAccountController
{
    public function __construct(
        private PartnerAccountProvisioner $provisioner,
        private SessionService $sessions,
    ) {
    }

    /**
     * Створення акаунта постачальника або водія.
     *
     * AUTH-24: якщо `passwordPlain` не передано, пароль генерується і
     * повертається РІВНО ОДИН РАЗ — далі лише перегенерація.
     */
    #[Route('', name: 'internal_partner_account_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);
        $rawRole = $payload->requiredString('role');
        $role = PartnerRole::tryFrom($rawRole);

        if (null === $role) {
            throw new ValidationException([\sprintf('Невідома роль «%s» для партнерського контуру.', $rawRole)]);
        }

        $credentials = $this->provisioner->create(new CreatePartnerAccount(
            login: $payload->requiredString('login'),
            role: $role,
            supplierId: $payload->requiredString('supplierId'),
            passwordPlain: $payload->optionalString('passwordPlain'),
            driverProfileId: $payload->optionalString('driverProfileId'),
            active: $payload->boolean('active', true),
        ));

        return new JsonResponse($credentials->toArray(), Response::HTTP_CREATED);
    }

    /**
     * AUTH-25: перегенерація пароля водія постачальником — старий пароль
     * недійсний негайно, всі сесії водія відкликаються.
     */
    #[Route('/{accountId}/password/regenerate', name: 'internal_partner_account_regenerate', methods: ['POST'])]
    public function regeneratePassword(string $accountId): JsonResponse
    {
        return new JsonResponse($this->provisioner->regeneratePassword($accountId)->toArray());
    }

    /** AUTH-28: деактивація постачальника вимикає логін усім його акаунтам. */
    #[Route('/suppliers/{supplierId}/suspend', name: 'internal_partner_supplier_suspend', methods: ['POST'])]
    public function suspendSupplier(string $supplierId): JsonResponse
    {
        return new JsonResponse([
            'supplierId' => $supplierId,
            'deactivatedAccounts' => $this->provisioner->suspendSupplier($supplierId),
        ]);
    }

    /** «Вийти з усіх пристроїв» для конкретного акаунта (AUTH-32). */
    #[Route('/{accountId}/sessions', name: 'internal_partner_account_logout_all', methods: ['DELETE'])]
    public function revokeSessions(string $accountId): JsonResponse
    {
        $this->sessions->logoutAll($accountId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
