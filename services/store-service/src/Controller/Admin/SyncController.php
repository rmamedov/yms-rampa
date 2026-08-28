<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Service\BranchSyncService;
use App\Domain\Sync\SyncTrigger;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розділ «Синхронізація MCP» (5.6, 11.1): журнал запусків і ручний запуск.
 *
 * Синхронізація стосується всього довідника, а не окремого магазину, тож
 * магазинного скоупу тут немає — перевіряється лише ідентичність staff-контуру.
 */
#[Route('/api/admin/v1/sync')]
final class SyncController extends AbstractController
{
    public function __construct(
        private readonly BranchSyncService $sync,
        private readonly ActorResolver $actors,
    ) {
    }

    /** SYNC-01: журнал запусків із серверною пагінацією. */
    #[Route('/log', methods: ['GET'])]
    public function log(Request $request): JsonResponse
    {
        $this->actors->staff($request);

        return new JsonResponse($this->sync->log(
            $request->query->getInt('page', 1),
            $request->query->getInt('perPage', 20),
        ));
    }

    /**
     * SYNC-01: деталізація одного запуску — які саме філії зʼявились,
     * змінились і зникли, а не лише скільки їх.
     */
    #[Route('/log/{runId}', methods: ['GET'])]
    public function logEntry(string $runId, Request $request): JsonResponse
    {
        $this->actors->staff($request);

        return new JsonResponse($this->sync->entry($runId));
    }

    /**
     * SYNC-02 / INT-05: ручний запуск. Повторний запуск під час активної
     * синхронізації відхиляється з кодом SYNC_ALREADY_RUNNING (409).
     */
    #[Route('/run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);

        $report = $this->sync->run(SyncTrigger::Manual, $actor->userId);

        return new JsonResponse($report->toArray());
    }
}
