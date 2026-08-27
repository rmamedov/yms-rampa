<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\ReservedSlotRuleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD правил резервування слотів — вкладка «Резерви» (5.3.5).
 */
#[Route('/api/admin/v1/stores/{storeId}/reserved-slot-rules', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class ReservedSlotRuleController extends AbstractController
{
    public function __construct(
        private readonly ReservedSlotRuleService $rules,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $storeId, Request $request): JsonResponse
    {
        $activeOnly = $request->query->has('active')
            ? $request->query->getBoolean('active')
            : null;

        return new JsonResponse(['items' => $this->rules->list($storeId, $activeOnly)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->rules->create($storeId, Payload::fromJson($request->getContent()), $request->headers->get('X-User-Id')),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{ruleId}', methods: ['PUT', 'PATCH'])]
    public function update(string $storeId, string $ruleId, Request $request): JsonResponse
    {
        return new JsonResponse($this->rules->update($storeId, $ruleId, Payload::fromJson($request->getContent())));
    }

    #[Route('/{ruleId}', methods: ['DELETE'])]
    public function delete(string $storeId, string $ruleId): JsonResponse
    {
        $this->rules->delete($storeId, $ruleId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
