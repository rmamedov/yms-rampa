<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\AuthException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Перетворення доменних помилок на RFC 7807 `application/problem+json`.
 *
 * AUTH-53: у тіло відповіді потрапляє лише текст українською з розділу 3.7;
 * технічні деталі (technicalReason, стек) лишаються в логах.
 */
final readonly class AuthExceptionListener implements EventSubscriberInterface
{
    public function __construct(private ProblemJsonFactory $problems)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $requestId = ProblemJsonFactory::requestId($request);
        $exception = $event->getThrowable();

        if ($exception instanceof AuthException) {
            $event->setResponse($this->problems->fromAuthException($exception, $requestId));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $event->setResponse($this->problems->build(
                status: $exception->getStatusCode(),
                title: 'Помилка запиту',
                detail: $exception->getMessage(),
                code: 'HTTP_ERROR',
                requestId: $requestId,
            ));

            return;
        }

        $event->setResponse($this->problems->build(
            status: 500,
            title: 'Внутрішня помилка сервісу',
            detail: 'Сталася непередбачена помилка. Спробуйте пізніше.',
            code: 'INTERNAL_ERROR',
            requestId: $requestId,
        ));
    }
}
