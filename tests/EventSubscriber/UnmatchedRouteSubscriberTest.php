<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use Throwable;
use App\EventSubscriber\UnmatchedRouteSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UnmatchedRouteSubscriberTest extends TestCase
{

    public function testNotFoundIsLogged(): void
    {
        $request = Request::create('/weatherstation/updateweatherstation.php', 'GET', ['PASSKEY' => 'x']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('GET /weatherstation/updateweatherstation.php'));

        $subscriber = new UnmatchedRouteSubscriber($logger);
        $subscriber->onKernelException($this->exceptionEvent($request, new NotFoundHttpException()));
    }

    public function testMethodNotAllowedIsLogged(): void
    {
        $request = Request::create('/froggit', 'DELETE');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('DELETE /froggit'));

        $subscriber = new UnmatchedRouteSubscriber($logger);
        $subscriber->onKernelException($this->exceptionEvent($request, new MethodNotAllowedHttpException(['GET'])));
    }

    public function testUnrelatedExceptionIsNotLogged(): void
    {
        $request = Request::create('/froggit');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $subscriber = new UnmatchedRouteSubscriber($logger);
        $subscriber->onKernelException($this->exceptionEvent($request, new RuntimeException('boom')));
    }

    private function exceptionEvent(Request $request, Throwable $throwable): ExceptionEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

}
