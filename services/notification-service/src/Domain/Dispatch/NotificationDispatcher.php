<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Clock\Clock;
use App\Domain\Notification\Exception\TemplateRenderingException;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationRepository;
use App\Domain\Notification\NotificationStatus;
use App\Domain\Notification\TemplateRenderer;
use App\Domain\Preference\OptOutRegistry;
use App\Domain\Security\SecretMasker;
use App\Domain\Transport\OutgoingMessage;
use App\Domain\Transport\TransportException;
use App\Domain\Transport\TransportRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Диспетчер відправки сповіщень.
 *
 * Реалізує:
 * - NOT-04: до N спроб з експоненційним backoff (за замовчуванням 1 і 5 хв,
 *   стеля 15 хв); стан кожної спроби фіксується у сховищі, тому недоступність
 *   провайдера НЕ призводить до втрати повідомлення — воно лишається в черзі
 *   і буде підхоплене наступним прогоном `processDue()`;
 * - NOT-04 (резервний канал): критичне сповіщення, що остаточно впало,
 *   дублюється резервним каналом, якщо адреса отримувача заповнена;
 * - NOT-05: некритичні сповіщення не надсилаються користувачам з opt-out;
 * - NOT-15: одноразовий пароль водія не пишеться в журнал і затирається
 *   в payload одразу після відправки.
 *
 * Клас доменний: жодних залежностей від Symfony, HTTP чи Mongo.
 */
final readonly class NotificationDispatcher
{
    public function __construct(
        private NotificationRepository $repository,
        private TransportRegistry $transports,
        private TemplateRenderer $renderer,
        private RetryPolicy $retryPolicy,
        private Clock $clock,
        private SecretMasker $masker,
        private OptOutRegistry $optOut,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Ставить сповіщення в чергу. Повертає null, якщо отримувач відмовився
     * від некритичних сповіщень (NOT-05).
     */
    public function queue(NotificationRequest $request): ?Notification
    {
        if ($this->isSuppressedByOptOut($request)) {
            $this->logger->info('Сповіщення пропущено через opt-out користувача', [
                'template' => $request->template->code(),
                'channel' => $request->channel->value,
                'recipientId' => $request->recipientId,
            ]);

            return null;
        }

        $notification = Notification::queue(
            id: $this->repository->nextIdentity(),
            channel: $request->channel,
            recipient: $request->recipient,
            template: $request->template,
            payload: $request->payload,
            now: $this->clock->now(),
            correlationId: $request->correlationId,
            recipientId: $request->recipientId,
            fallbackRecipient: $request->fallbackRecipient,
        );

        $this->repository->save($notification);

        return $notification;
    }

    /**
     * Ставить у чергу і одразу робить першу спробу відправки.
     */
    public function send(NotificationRequest $request): ?Notification
    {
        $notification = $this->queue($request);
        if (null === $notification) {
            return null;
        }

        $this->dispatch($notification);

        return $notification;
    }

    /**
     * Одна спроба відправки. Метод НІКОЛИ не кидає виняток транспорту:
     * будь-який збій фіксується у сповіщенні і зберігається (NOT-04).
     */
    public function dispatch(Notification $notification): DispatchResult
    {
        if ($notification->status()->isFinal()) {
            return DispatchResult::Skipped;
        }

        $now = $this->clock->now();

        try {
            $rendered = $this->renderer->render(
                $notification->template(),
                $notification->channel(),
                $notification->payload(),
            );
        } catch (TemplateRenderingException $e) {
            // Помилка в даних — ретраї не допоможуть.
            $notification->markFailed('Помилка рендерингу шаблону: '.$e->getMessage(), $now);
            $notification->forgetSecrets();
            $this->repository->save($notification);
            $this->logger->error('Не вдалося відрендерити сповіщення', $this->logContext($notification, $e->getMessage()));

            return DispatchResult::Failed;
        }

        try {
            $transport = $this->transports->for($notification->channel());
            $receipt = $transport->send(OutgoingMessage::fromRendered(
                $notification->id(),
                $notification->recipient(),
                $rendered,
            ));
        } catch (TransportException $e) {
            return $this->handleFailure($notification, $e, $now);
        }

        $notification->markSent($now, $receipt->providerMessageId);
        // NOT-15: пароль не персиститься після відправки.
        $notification->forgetSecrets();
        $this->repository->save($notification);

        $this->logger->info('Сповіщення відправлено', $this->logContext($notification) + [
            'provider' => $receipt->provider,
            'providerMessageId' => $receipt->providerMessageId,
        ]);

        return DispatchResult::Sent;
    }

    /**
     * Прогін черги: бере всі сповіщення, у яких настав час чергової спроби.
     *
     * @return array<string, int> лічильники за результатами
     */
    public function processDue(int $limit = 100): array
    {
        $counters = [
            DispatchResult::Sent->value => 0,
            DispatchResult::Retrying->value => 0,
            DispatchResult::Failed->value => 0,
            DispatchResult::Skipped->value => 0,
        ];

        foreach ($this->repository->findDue($this->clock->now(), $limit) as $notification) {
            $result = $this->dispatch($notification);
            ++$counters[$result->value];
        }

        return $counters;
    }

    /**
     * Підтвердження доставки з delivery-report провайдера (NOT-03).
     */
    public function confirmDelivery(string $notificationId): bool
    {
        $notification = $this->repository->find($notificationId);
        if (null === $notification || NotificationStatus::Sent !== $notification->status()) {
            return false;
        }

        $notification->markDelivered();
        $this->repository->save($notification);

        return true;
    }

    private function handleFailure(
        Notification $notification,
        TransportException $exception,
        \DateTimeImmutable $now,
    ): DispatchResult {
        $attemptsMade = $notification->attempts() + 1;
        $retryable = $exception->isRetryable() && $this->retryPolicy->shouldRetry($attemptsMade);

        if (!$retryable) {
            // Резервний канал ставимо в чергу ДО затирання секретів,
            // щоб критичне сповіщення дійшло з повним payload (NOT-04).
            $this->spawnFallback($notification);

            $notification->markFailed($exception->getMessage(), $now);
            $notification->forgetSecrets();
            $this->repository->save($notification);

            $this->logger->error(
                'Сповіщення не доставлено: спроби вичерпані',
                $this->logContext($notification, $exception->getMessage()),
            );

            return DispatchResult::Failed;
        }

        $delay = $this->retryPolicy->delayForAttempt($attemptsMade);
        $notification->registerFailedAttempt(
            $exception->getMessage(),
            $now,
            $now->add(new \DateInterval('PT'.$delay.'S')),
        );
        $this->repository->save($notification);

        $this->logger->warning(
            'Збій провайдера, заплановано повторну спробу',
            $this->logContext($notification, $exception->getMessage()) + ['retryInSeconds' => $delay],
        );

        return DispatchResult::Retrying;
    }

    /**
     * NOT-04: дублювання критичного сповіщення резервним каналом.
     */
    private function spawnFallback(Notification $notification): void
    {
        if (!$notification->template()->isCritical() || $notification->fallbackSpawned()) {
            return;
        }

        $fallbackChannel = $notification->channel()->fallback();
        $fallbackRecipient = $notification->fallbackRecipient();

        if (null === $fallbackChannel || null === $fallbackRecipient || '' === trim($fallbackRecipient)) {
            return;
        }
        if (!$notification->template()->canRenderFor($fallbackChannel) || !$this->transports->has($fallbackChannel)) {
            return;
        }

        $notification->markFallbackSpawned();

        $duplicate = Notification::queue(
            id: $this->repository->nextIdentity(),
            channel: $fallbackChannel,
            recipient: $fallbackRecipient,
            template: $notification->template(),
            payload: $notification->payload(),
            now: $this->clock->now(),
            correlationId: $notification->correlationId(),
            recipientId: $notification->recipientId(),
            fallbackRecipient: null,
        );
        $duplicate->markFallbackSpawned();

        $this->repository->save($duplicate);

        $this->logger->warning('Критичне сповіщення продубльовано резервним каналом', [
            'originalId' => $notification->id(),
            'fallbackId' => $duplicate->id(),
            'template' => $notification->template()->code(),
            'channel' => $fallbackChannel->value,
        ]);
    }

    private function isSuppressedByOptOut(NotificationRequest $request): bool
    {
        // NOT-05: критичні сповіщення вимкнути неможливо.
        if ($request->template->isCritical() || null === $request->recipientId) {
            return false;
        }

        return $this->optOut->isOptedOut($request->recipientId, $request->template);
    }

    /**
     * Контекст для журналу. Секрети замасковані (NOT-15).
     *
     * @return array<string, mixed>
     */
    private function logContext(Notification $notification, ?string $error = null): array
    {
        $context = [
            'notificationId' => $notification->id(),
            'template' => $notification->template()->code(),
            'channel' => $notification->channel()->value,
            'recipient' => $notification->recipient(),
            'attempts' => $notification->attempts(),
            'status' => $notification->status()->value,
            'payload' => $notification->maskedPayload($this->masker),
        ];

        if (null !== $error) {
            $context['error'] = $this->masker->maskText(
                $error,
                $notification->payload(),
                $notification->template()->sensitiveVariables(),
            );
        }

        return $context;
    }
}
