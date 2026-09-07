<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\DataReader;
use App\Service\InfluxService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DataViewControllerTest extends WebTestCase
{

    public function testRendersFormWithoutParameters(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/data/view');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
        $this->assertSelectorNotExists('table');
    }

    public function testShowsErrorForUnknownSink(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/data/view', [
            'data_view_search' => [
                'sink' => 'does-not-exist',
                'from' => '2026-09-01T00:00',
                'to' => '2026-09-02T00:00',
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('li', 'invalid');
    }

    public function testShowsErrorWhenFromIsAfterTo(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/data/view', [
            'data_view_search' => [
                'sink' => 'mariadb',
                'from' => '2026-09-02T00:00',
                'to' => '2026-09-01T00:00',
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('li', 'must not be after');
    }

    public function testRendersTableForMariaDbSink(): void
    {
        $client = self::createClient();

        $dataReader = $this->createMock(DataReader::class);
        $dataReader->expects($this->once())->method('read')->willReturn([
            ['timestamp' => '2026-09-01 12:00:00', 'device' => '', 'attribute' => 'php-unit', 'value' => 12.3],
        ]);
        self::getContainer()->set(DataReader::class, $dataReader);

        $client->request(Request::METHOD_GET, '/data/view', [
            'data_view_search' => [
                'sink' => 'mariadb',
                'from' => '2026-09-01T00:00',
                'to' => '2026-09-02T00:00',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('table');
        $this->assertSelectorTextContains('table', 'php-unit');
    }

    public function testRendersTableForInfluxSink(): void
    {
        $client = self::createClient();

        $influxService = $this->createMock(InfluxService::class);
        $influxService->expects($this->once())->method('read')->willReturn([]);
        self::getContainer()->set(InfluxService::class, $influxService);

        $client->request(Request::METHOD_GET, '/data/view', [
            'data_view_search' => [
                'sink' => 'influx',
                'from' => '2026-09-01T00:00',
                'to' => '2026-09-02T00:00',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('table');
        $this->assertSelectorTextContains('.hint-box', 'No readings');
    }

}
