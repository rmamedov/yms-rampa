<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\StoreConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Версіонована конфігурація магазину: вкладки «Прийом поставок», «Слоти», «Обмеження»
 * (5.3.2–5.3.4). Редагування завжди створює нову версію (DATA-09).
 */
#[Route('/api/admin/v1/stores/{storeId}/configurations', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class StoreConfigurationController extends AbstractController
{
    public function __construct(
        private readonly StoreConfigurationService $configurations,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $storeId): JsonResponse
    {
        return new JsonResponse(['items' => $this->configurations->listVersions($storeId)]);
    }

    #[Route('/current', methods: ['GET'])]
    public function current(string $storeId): JsonResponse
    {
        return new JsonResponse($this->configurations->current($storeId));
    }

    /** STC-60: нова версія набирає чинності «з дати X» (не раніше завтра). */
    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        $payload = Payload::fromJson($request->getContent());
        $createdBy = $request->headers->get('X-User-Id');

        return new JsonResponse(
            $this->configurations->createVersion($storeId, $payload, $createdBy),
            Response::HTTP_CREATED,
        );
    }
}
