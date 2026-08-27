<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\SlotBlockService;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Разові блокування слотів — вкладка «Блокування слотів» (5.3.6).
 *
 * Скоуп магазину перевіряється на кожному ендпоїнті (RBAC-13, RBAC-18).
 */
#[Route('/api/admin/v1/stores/{storeId}/slot-blocks', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class SlotBlockController extends AbstractController
{
    public function __construct(
        private readonly SlotBlockService $blocks,
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

        return new JsonResponse(['items' => $this->blocks->list($storeId, $activeOnly)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);
        $actor->assertCanActOnStore($storeId);

        return new JsonResponse(
            $this->blocks->create($storeId, Payload::fromJson($request->getContent()), $actor->userId),
            Response::HTTP_CREATED,
        );
    }

    /** STC-52: дострокове зняття блокування — подія SlotReleased. */
    #[Route('/{blockId}/release', methods: ['POST'])]
    public function release(string $storeId, string $blockId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanActOnStore($storeId);

        return new JsonResponse($this->blocks->release($storeId, $blockId));
    }

    #[Route('/{blockId}', methods: ['DELETE'])]
    public function delete(string $storeId, string $blockId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanActOnStore($storeId);

        $this->blocks->delete($storeId, $blockId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
