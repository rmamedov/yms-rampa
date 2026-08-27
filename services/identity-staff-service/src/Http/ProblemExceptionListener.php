<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Shared\DomainException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Страхувальна мережа: будь-яка неперехоплена помилка також віддається
 * як RFC 7807 `application/problem+json` (RBAC-33), а не як HTML-сторінка.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class ProblemExceptionListener
{
    public function __construct(private ProblemDetailsFactory $problems)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if ($exception instanceof DomainException) {
            $event->setResponse($this->problems->fromDomainException($exception, $request));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $event->setResponse($this->problems->create(
                status: $exception->getStatusCode(),
                detail: 404 === $exception->getStatusCode()
                    ? 'Ресурс не знайдено'
                    : 'Запит не може бути виконано.',
                code: 404 === $exception->getStatusCode() ? 'RESOURCE_NOT_FOUND' : 'REQUEST_FAILED',
                request: $request,
            ));

            return;
        }

        // AUTH-53: технічні деталі — тільки в логах
        $event->setResponse($this->problems->create(
            status: 500,
            detail: 'Сталася непередбачена помилка. Спробуйте пізніше.',
            code: 'INTERNAL_ERROR',
            request: $request,
        ));
    }
}
