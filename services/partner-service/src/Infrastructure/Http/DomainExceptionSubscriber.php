<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Shared\DomainException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Перетворює доменні винятки на відповіді RFC 7807, щоб контролери
 * не займалися обробкою помилок і жодна помилка не «протікала» в
 * стандартному HTML-форматі Symfony.
 */
final readonly class DomainExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private ProblemJsonFactory $problems)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 32]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof DomainException) {
            return;
        }

        // Публічний API (/api/) і службові маршрути (/internal/) віддають
        // помилки в одному форматі: booking-service читає з відповіді поле
        // `code` (напр. SUPPLIER_NOT_FOUND) і без problem+json отримав би
        // HTML-сторінку помилки Symfony замість машинної причини.
        $path = $event->getRequest()->getPathInfo();

        if (!str_starts_with($path, '/api/') && !str_starts_with($path, '/internal/')) {
            return;
        }

        $event->setResponse($this->problems->fromDomainException($exception, $event->getRequest()));
    }
}
