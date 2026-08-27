<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Domain\Exception\MalformedEventException;
use App\Domain\Projection\ProjectionOutcome;
use App\Infrastructure\Messaging\DomainEventConsumer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Приймання пакета доменних подій у read-моделі (KPI-05).
 *
 * КОНТРАКТ:
 *
 *   POST /internal/v1/analytics/events
 *   {"events":[{"eventId","name","occurredAt","payload":{…}}, …]}
 *
 *   200 {"received":N,"applied":a,"duplicate":d,"ignored":i,"orphan":o,
 *        "failed":[{"eventId":"…","reason":"…"}]}
 *   422 problem+json code=EVENT_BATCH_MALFORMED — тіло не є обʼєктом
 *       з масивом `events` (той самий статус, що й для окремої непридатної
 *       події, — ProblemJsonExceptionListener бере його з MalformedEventException).
 *
 * ЧОМУ ПАКЕТ, А НЕ ОДНА ПОДІЯ. Релей ходить сюди за розкладом і несе десятки
 * подій за раз; окремий HTTP-запит на кожну коштував би більше, ніж уся
 * проєкція.
 *
 * ЧОМУ ОДНА ЗІПСОВАНА ПОДІЯ НЕ ВАЛИТЬ ПАКЕТ. Відповідь 4xx означала б для
 * релея «доставка не вдалася», і він вічно повторював би той самий пакет,
 * застрягши на одному непридатному записі. Тому кожна подія обробляється
 * окремо, а непридатні повертаються переліком `failed` — релей їх залогує,
 * але черга рухається далі.
 *
 * ІДЕМПОТЕНТНІСТЬ. Доставка at-least-once: повторний пакет із тими самими
 * eventId дає `duplicate` і не змінює жодного факту (гарантія EventProjector).
 *
 * Префікс `/internal/v1/`, а НЕ `/api/`: маршрут обслуговує лише внутрішній
 * шлюз nginx на 127.0.0.1:8081 (map `$yms_internal_service`, префікс
 * /internal/v1/analytics), назовні він недосяжний. Через auth_request такі
 * запити не проходять і заголовків ідентичності не мають, тому ActorResolver
 * тут свідомо НЕ викликається — на відміну від /api/admin/v1/analytics/…
 */
#[AsController]
#[Route('/internal/v1/analytics/events')]
final readonly class InternalEventIngestController
{
    public function __construct(private DomainEventConsumer $consumer)
    {
    }

    #[Route('', name: 'internal_analytics_events_ingest', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $events = $this->eventsFromBody($request->getContent());

        $counters = array_fill_keys(
            array_map(static fn (ProjectionOutcome $o): string => $o->value, ProjectionOutcome::cases()),
            0,
        );
        $failed = [];

        foreach ($events as $index => $event) {
            if (!is_array($event)) {
                $failed[] = [
                    'eventId' => null,
                    'reason' => sprintf('Елемент %d не є обʼєктом події.', $index),
                ];

                continue;
            }

            /** @var array<string, mixed> $event */
            try {
                ++$counters[$this->consumer->consumeArray($event)->outcome->value];
            } catch (MalformedEventException $exception) {
                $eventId = $event['eventId'] ?? null;
                $failed[] = [
                    'eventId' => is_string($eventId) ? $eventId : null,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return new JsonResponse([
            'received' => count($events),
            'applied' => $counters[ProjectionOutcome::Applied->value],
            'duplicate' => $counters[ProjectionOutcome::Duplicate->value],
            'ignored' => $counters[ProjectionOutcome::Ignored->value],
            'orphan' => $counters[ProjectionOutcome::Orphan->value],
            'failed' => $failed,
        ]);
    }

    /**
     * @return list<mixed>
     *
     * @throws MalformedEventException тіло не є пакетом подій
     */
    private function eventsFromBody(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MalformedEventException(
                'Тіло запиту не є коректним JSON: ' . $exception->getMessage(),
                'EVENT_BATCH_MALFORMED',
            );
        }

        $events = is_array($decoded) ? ($decoded['events'] ?? null) : null;

        if (!is_array($events) || !array_is_list($events)) {
            throw new MalformedEventException(
                'Тіло запиту має бути обʼєктом із масивом «events».',
                'EVENT_BATCH_MALFORMED',
            );
        }

        return $events;
    }
}
