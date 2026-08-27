<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\DomainException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Перетворює доменні винятки на відповіді RFC 7807 для маршрутів /api/* і /internal/*.
 *
 * Службовий контур /internal/* включений свідомо: booking-service розрізняє
 * «магазину немає» і «сервіс упав» саме за кодом у тілі відповіді, тож без цього
 * він отримував би замість problem+json HTML-сторінку помилки Symfony.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final readonly class DomainExceptionListener
{
    /** @var list<string> */
    private const array JSON_PREFIXES = ['/api/', '/internal/'];

    public function __construct(
        private bool $debug = false,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!self::isJsonRoute($request->getPathInfo())) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof DomainException) {
            $event->setResponse(ProblemJsonFactory::fromDomainException($exception, $request));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $event->setResponse(ProblemJsonFactory::build(
                status: $exception->getStatusCode(),
                title: 'Запит не оброблено',
                detail: $exception->getMessage(),
                code: 404 === $exception->getStatusCode() ? 'NOT_FOUND' : 'HTTP_ERROR',
                request: $request,
            ));

            return;
        }

        $event->setResponse(ProblemJsonFactory::internal($exception, $request, $this->debug));
    }

    private static function isJsonRoute(string $path): bool
    {
        foreach (self::JSON_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
