<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\PartnerUser\PartnerUser;
use App\Domain\Service\DriverCredentials;
use App\Domain\Service\DriverService;
use App\Infrastructure\Http\JsonBody;
use App\Infrastructure\Http\SupplierContext;
use App\Infrastructure\Http\View;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розділ «Водії» кабінету постачальника (SUP-DRV-01…SUP-DRV-05).
 */
#[Route('/api/supplier/v1/drivers')]
final class SupplierDriverController
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly SupplierContext $context,
    ) {
    }

    /**
     * SUP-DRV-01: список водіїв постачальника зі статусом, телефоном, ПІБ і авто.
     */
    #[Route('', name: 'supplier_drivers_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $items = $this->drivers->list($this->context->supplierId($request));

        return new JsonResponse([
            'items' => array_map(static fn (PartnerUser $d): array => View::driver($d), $items),
            'total' => \count($items),
        ]);
    }

    /**
     * SUP-DRV-02, SUP-DRV-03: створення водія.
     *
     * У відповіді пароль повертається РІВНО ОДИН РАЗ — фронтенд показує його
     * в модалці «Запишіть пароль — повторно він не показується». Паралельно
     * подія DriverCreated іде в notification-service для SMS.
     */
    #[Route('', name: 'supplier_drivers_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $supplierId = $this->context->supplierId($request);
        $body = JsonBody::fromRequest($request);

        $credentials = $this->drivers->createDriver(
            supplierId: $supplierId,
            phone: $body->requiredString('phone'),
            firstName: $body->requiredString('firstName'),
            lastName: $body->requiredString('lastName'),
            defaultVehicleId: $body->optionalString('defaultVehicleId'),
        );

        return new JsonResponse(self::withCredentials($credentials), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'supplier_drivers_get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::driver(
            $this->drivers->getDriver($this->context->supplierId($request), $id),
        ));
    }

    #[Route('/{id}', name: 'supplier_drivers_update', methods: ['PATCH', 'PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $supplierId = $this->context->supplierId($request);
        $body = JsonBody::fromRequest($request);
        $driver = $this->drivers->getDriver($supplierId, $id);

        if ($body->has('phone')) {
            $driver = $this->drivers->changePhone($supplierId, $id, $body->requiredString('phone'));
        }

        if ($body->has('defaultVehicleId')) {
            $driver = $this->drivers->assignVehicle($supplierId, $id, $body->optionalString('defaultVehicleId'));
        }

        return new JsonResponse(View::driver($driver));
    }

    /**
     * SUP-DRV-04: перегенерація пароля — старий інвалідовується,
     * новий показується одноразово і дублюється SMS.
     */
    #[Route('/{id}/regenerate-password', name: 'supplier_drivers_regenerate_password', methods: ['POST'])]
    public function regeneratePassword(string $id, Request $request): JsonResponse
    {
        $credentials = $this->drivers->regeneratePassword($this->context->supplierId($request), $id);

        return new JsonResponse(self::withCredentials($credentials));
    }

    /**
     * SUP-DRV-05: деактивація водія — вхід у driver-web блокується,
     * історія зберігається.
     */
    #[Route('/{id}/deactivate', name: 'supplier_drivers_deactivate', methods: ['POST'])]
    public function deactivate(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::driver(
            $this->drivers->deactivate($this->context->supplierId($request), $id),
        ));
    }

    #[Route('/{id}/activate', name: 'supplier_drivers_activate', methods: ['POST'])]
    public function activate(string $id, Request $request): JsonResponse
    {
        return new JsonResponse(View::driver(
            $this->drivers->activate($this->context->supplierId($request), $id),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function withCredentials(DriverCredentials $credentials): array
    {
        return View::driver($credentials->driver) + [
            'login' => $credentials->login,
            'password' => $credentials->password,
            'passwordNotice' => 'Запишіть пароль — повторно він не показується.',
        ];
    }
}
