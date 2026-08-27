<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\ReservedSlotRuleService;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD правил резервування слотів — вкладка «Резерви» (5.3.5).
 *
 * Скоуп магазину перевіряється на кожному ендпоїнті (RBAC-13, RBAC-18).
 */
#[Route('/api/admin/v1/stores/{storeId}/reserved-slot-rules', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class ReservedSlotRuleController extends AbstractController
{
    public function __construct(
        private readonly ReservedSlotRuleService $rules,
        private readonly ActorResolver $actors,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanReadStore($storeId);

        $activeOnly = $request->query->has('active')
            ? $request->query->getBoolean('active')
            : null;

        return new JsonResponse(['items' => $this->rules->list($storeId, $activeOnly)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);
        $actor->assertCanActOnStore($storeId);

        return new JsonResponse(
            $this->rules->create($storeId, Payload::fromJson($request->getContent()), $actor->userId),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{ruleId}', methods: ['PUT', 'PATCH'])]
    public function update(string $storeId, string $ruleId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanActOnStore($storeId);

        return new JsonResponse($this->rules->update($storeId, $ruleId, Payload::fromJson($request->getContent())));
    }

    #[Route('/{ruleId}', methods: ['DELETE'])]
    public function delete(string $storeId, string $ruleId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanActOnStore($storeId);

        $this->rules->delete($storeId, $ruleId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
