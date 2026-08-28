<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Application\Dto\Payload;
use App\Application\Service\StoreConfigurationService;
use App\Infrastructure\Http\ActorResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Версіонована конфігурація магазину: вкладки «Прийом поставок», «Слоти», «Обмеження»
 * (5.3.2–5.3.4). Редагування завжди створює нову версію (DATA-09).
 *
 * Скоуп магазину перевіряється на кожному ендпоїнті (RBAC-13, RBAC-18).
 */
#[Route('/api/admin/v1/stores/{storeId}/configurations', requirements: ['storeId' => '[0-9a-fA-F-]{36}'])]
final class StoreConfigurationController extends AbstractController
{
    public function __construct(
        private readonly StoreConfigurationService $configurations,
        private readonly ActorResolver $actors,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanReadStore($storeId);

        return new JsonResponse(['items' => $this->configurations->listVersions($storeId)]);
    }

    #[Route('/current', methods: ['GET'])]
    public function current(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanReadStore($storeId);

        return new JsonResponse($this->configurations->current($storeId));
    }

    /**
     * Остання версія — та, яку редагує екран налаштувань, навіть якщо вона
     * ще не набрала чинності. Див. StoreConfigurationService::latest().
     */
    #[Route('/latest', methods: ['GET'])]
    public function latest(string $storeId, Request $request): JsonResponse
    {
        $this->actors->staff($request)->assertCanReadStore($storeId);

        return new JsonResponse($this->configurations->latest($storeId));
    }

    /** STC-60: нова версія набирає чинності «з дати X» (не раніше завтра). */
    #[Route('', methods: ['POST'])]
    public function create(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->staff($request);
        $actor->assertCanActOnStore($storeId);

        $payload = Payload::fromJson($request->getContent());

        return new JsonResponse(
            $this->configurations->createVersion($storeId, $payload, $actor->userId),
            Response::HTTP_CREATED,
        );
    }
}
