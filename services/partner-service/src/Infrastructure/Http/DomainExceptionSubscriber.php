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

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse($this->problems->fromDomainException($exception, $event->getRequest()));
    }
}
