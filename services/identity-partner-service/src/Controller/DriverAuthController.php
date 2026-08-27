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
 * Автентифікація водія — контур partner, застосунок driver-web
 * (AUTH-40, DRV-06: `POST /api/driver/v1/auth/login|refresh|logout`).
 *
 * Логін — телефон, який нормалізується до `+380XXXXXXXXX` на боці сервісу
 * (AUTH-23), навіть якщо клієнт надіслав `067 123 45 67`.
 * Прапорець «Запамʼятати мене» увімкнений за замовчуванням: refresh 90 днів
 * (AUTH-27, DRV-07); без нього — 30 днів.
 */
#[Route('/api/driver/v1/auth')]
final readonly class DriverAuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private SessionService $sessions,
    ) {
    }

    #[Route('/login', name: 'driver_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);

        $result = $this->authentication->login(
            client: ClientType::DriverWeb,
            rawLogin: $payload->requiredString('phone'),
            password: $payload->requiredString('password'),
            rememberMe: $payload->boolean('rememberMe', true),
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse($result->toArray());
    }

    #[Route('/refresh', name: 'driver_auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);

        $result = $this->sessions->refresh(
            client: ClientType::DriverWeb,
            refreshToken: $payload->requiredString('refreshToken'),
            ip: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        return new JsonResponse($result->toArray());
    }

    /** DRV-09: «Вийти» інвалідовує refresh-токен поточної сесії. */
    #[Route('/logout', name: 'driver_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $payload = JsonRequestPayload::fromRequest($request);
        $this->sessions->logout($payload->requiredString('refreshToken'));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
