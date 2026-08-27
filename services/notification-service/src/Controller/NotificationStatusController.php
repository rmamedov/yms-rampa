<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Notification\NotificationRepository;
use App\Domain\Security\SecretMasker;
use App\Infrastructure\Http\ProblemJsonFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Статус доставки сповіщення для адмінки (NOT-03).
 *
 * Контур staff, схема URL проєкту: /api/admin/v1/...
 * Помилки віддаються у форматі RFC 7807 (application/problem+json)
 * з розширеннями code і requestId.
 */
final readonly class NotificationStatusController
{
    public function __construct(
        private NotificationRepository $repository,
        private SecretMasker $masker,
        private ProblemJsonFactory $problems,
    ) {
    }

    #[Route('/api/admin/v1/notifications/{id}', name: 'admin_notification_status', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $notification = $this->repository->find($id);

        if (null === $notification) {
            return $this->problems->create(
                status: 404,
                title: 'Не знайдено',
                detail: \sprintf('Сповіщення «%s» не знайдено.', $id),
                code: 'NOTIFICATION_NOT_FOUND',
                request: $request,
            );
        }

        return new JsonResponse([
            'id' => $notification->id(),
            'channel' => $notification->channel()->value,
            'template' => $notification->template()->code(),
            'recipient' => $notification->recipient(),
            'status' => $notification->status()->value,
            'attempts' => $notification->attempts(),
            'sentAt' => $notification->sentAt()?->format(\DATE_ATOM),
            'nextAttemptAt' => $notification->nextAttemptAt()?->format(\DATE_ATOM),
            'error' => $notification->error(),
            'correlationId' => $notification->correlationId(),
            // Секрети (пароль водія) ніколи не віддаються назовні (NOT-15).
            'payload' => $notification->maskedPayload($this->masker),
        ]);
    }
}
