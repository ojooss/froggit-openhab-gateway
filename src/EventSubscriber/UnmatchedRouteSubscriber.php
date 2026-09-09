<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

readonly class UnmatchedRouteSubscriber implements EventSubscriberInterface
{

    public function __construct(
        private LoggerInterface $froggitLogger,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof NotFoundHttpException && !$throwable instanceof MethodNotAllowedHttpException) {
            return;
        }

        $request = $event->getRequest();

        $this->froggitLogger->warning(
            sprintf('unmatched request: %s %s', $request->getMethod(), $request->getPathInfo()),
            [
                'query' => $request->query->all(),
                'body' => $request->request->all(),
                'ip' => $request->getClientIp(),
            ]
        );
    }

}
