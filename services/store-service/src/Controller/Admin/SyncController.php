<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Service\BranchSyncService;
use App\Domain\Sync\SyncTrigger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розділ «Синхронізація MCP» (5.6, 11.1): журнал запусків і ручний запуск.
 */
#[Route('/api/admin/v1/sync')]
final class SyncController extends AbstractController
{
    public function __construct(
        private readonly BranchSyncService $sync,
    ) {
    }

    /** SYNC-01: журнал запусків із серверною пагінацією. */
    #[Route('/log', methods: ['GET'])]
    public function log(Request $request): JsonResponse
    {
        return new JsonResponse($this->sync->log(
            $request->query->getInt('page', 1),
            $request->query->getInt('perPage', 20),
        ));
    }

    /**
     * SYNC-02 / INT-05: ручний запуск. Повторний запуск під час активної
     * синхронізації відхиляється з кодом SYNC_ALREADY_RUNNING (409).
     */
    #[Route('/run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $report = $this->sync->run(SyncTrigger::Manual, $request->headers->get('X-User-Id'));

        return new JsonResponse($report->toArray());
    }
}
