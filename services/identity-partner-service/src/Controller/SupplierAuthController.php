<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Account\ClientType;
use App\Domain\Auth\AuthenticationService;
use App\Domain\Auth\SessionService;
use App\Infrastructure\Http\JsonRequestPayload;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Автентифікація постачальника — контур partner, застосунок supplier-web
 * (AUTH-40: `POST /api/supplier/v1/auth/login|refresh|logout`).
 *
 * Логін — email + пароль (AUTH-21); refresh — 30 днів (розділ 3.4).
 */
#[Route('/api/supplier/v1/auth')]
final readonly class SupplierAuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private SessionService $sessions,
    ) {
    }

    #[Route('/login', name: 'supplier_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);

        $result = $this->authentication->login(
            client: ClientType::SupplierWeb,
            rawLogin: $payload->requiredString('login'),
            password: $payload->requiredString('password'),
            rememberMe: true,
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse($result->toArray());
    }

    #[Route('/refresh', name: 'supplier_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);

        $result = $this->sessions->refresh(
            client: ClientType::SupplierWeb,
            refreshToken: $payload->requiredString('refreshToken'),
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse($result->toArray());
    }

    #[Route('/logout', name: 'supplier_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);
        $this->sessions->logout($payload->requiredString('refreshToken'));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
