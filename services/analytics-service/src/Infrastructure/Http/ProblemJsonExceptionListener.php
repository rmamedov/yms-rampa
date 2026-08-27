<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Exception\AnalyticsException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Перетворює будь-яку необроблену помилку у відповідь RFC 7807
 * application/problem+json з полями code і requestId.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ProblemJsonExceptionListener
{
    public function __construct(private ProblemJsonResponseFactory $factory)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if ($exception instanceof AnalyticsException) {
            $event->setResponse($this->factory->fromDomainException($exception, $request));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $event->setResponse($this->factory->create(
                status: $exception->getStatusCode(),
                title: 'Помилка запиту',
                detail: $exception->getMessage(),
                code: 'HTTP_ERROR',
                request: $request,
            ));

            return;
        }

        $event->setResponse($this->factory->create(
            status: 500,
            title: 'Внутрішня помилка сервісу',
            detail: 'Не вдалося обробити запит. Спробуйте пізніше.',
            code: 'INTERNAL_ERROR',
            request: $request,
        ));
    }
}
