<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Throwable;
use App\Service\InfluxService;
use DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class InfluxServiceTest extends TestCase
{

    /**
     * @throws Throwable
     */
    public function testDisabledWhenUrlIsEmpty(): void
    {
        $influxService = new InfluxService(
            new ParameterBag(['influx' => ['url' => '', 'token' => '', 'org' => '', 'bucket' => '']]),
            new NullLogger()
        );

        // Must not throw / attempt any connection, even though no InfluxDB is reachable.
        $influxService->write('froggit', ['device' => ''], ['php-unit' => 12.3], new DateTime('now'));
        $influxService->flush();

        $this->assertSame([], $influxService->read('froggit', new DateTime('-1 hour'), new DateTime('now'), null, null, 10, 'DESC'));
    }

    /**
     * @throws Throwable
     */
    public function testFlushSwallowsWriteFailureByDefault(): void
    {
        $influxService = $this->serviceWithUnreachableUrl();
        $influxService->write('froggit', ['device' => ''], ['php-unit' => 12.3], new DateTime('now'));

        // Default (used by the register_shutdown_function safety net) must never throw.
        $influxService->flush();
        self::addToAssertionCount(1);
    }

    public function testFlushThrowsOnWriteFailureWhenRequested(): void
    {
        $influxService = $this->serviceWithUnreachableUrl();
        $influxService->write('froggit', ['device' => ''], ['php-unit' => 12.3], new DateTime('now'));

        // Used by DataWriter::flush() for the "all or nothing" controller reporting.
        $this->expectException(Throwable::class);
        $influxService->flush(throwOnFailure: true);
    }

    private function serviceWithUnreachableUrl(): InfluxService
    {
        return new InfluxService(
            new ParameterBag(['influx' => [
                'url' => 'http://influxdb-does-not-exist.invalid:9999',
                'token' => 'test',
                'org' => 'test',
                'bucket' => 'test',
            ]]),
            new NullLogger()
        );
    }

}
