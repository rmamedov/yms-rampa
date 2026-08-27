<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Єдина точка перетворення доменних винятків на RFC 7807.
 * Контролери не ловлять помилки самі — вони просто дають домену впасти.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final readonly class ProblemExceptionListener
{
    public function __construct(private ProblemResponseFactory $problems)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $requestId = RequestId::fromRequest($request);
        $response = $this->problems->fromThrowable($event->getThrowable(), $requestId);
        $response->headers->set(RequestId::HEADER, $requestId);

        $event->setResponse($response);
    }
}
