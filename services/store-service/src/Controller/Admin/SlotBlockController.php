<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\SlotBlockService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Разові блокування слотів — вкладка «Блокування слотів» (5.3.6).
 */
#[Route('/api/admin/v1/stores/{storeId}/slot-blocks', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class SlotBlockController extends AbstractController
{
    public function __construct(
        private readonly SlotBlockService $blocks,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $storeId, Request $request): JsonResponse
    {
        $activeOnly = $request->query->has('active')
            ? $request->query->getBoolean('active')
            : null;

        return new JsonResponse(['items' => $this->blocks->list($storeId, $activeOnly)]);
    }

    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        return new JsonResponse(
            $this->blocks->create($storeId, Payload::fromJson($request->getContent()), $request->headers->get('X-User-Id')),
            Response::HTTP_CREATED,
        );
    }

    /** STC-52: дострокове зняття блокування — подія SlotReleased. */
    #[Route('/{blockId}/release', methods: ['POST'])]
    public function release(string $storeId, string $blockId): JsonResponse
    {
        return new JsonResponse($this->blocks->release($storeId, $blockId));
    }

    #[Route('/{blockId}', methods: ['DELETE'])]
    public function delete(string $storeId, string $blockId): JsonResponse
    {
        $this->blocks->delete($storeId, $blockId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
