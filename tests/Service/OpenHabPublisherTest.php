<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OpenHabPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class OpenHabPublisherTest extends TestCase
{

    private function config(): ParameterBag
    {
        return new ParameterBag([
            'openhab' => [
                'base_url' => 'https://openhab.example.test',
                'token' => 'test-token',
                'mapping' => [
                    'php-unit' => 'Weather_Php_Unit',
                    'temperature-out' => 'Weather_Temperature_Out',
                ],
            ],
        ]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testPublishesKnownSensorsAndSkipsUnknown(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse('', ['http_code' => 200]);
        });

        $publisher = new OpenHabPublisher($this->config(), $httpClient, new NullLogger());
        $publisher->publish([
            'php-unit' => 12.345,
            'unknown-sensor' => 1.0,
        ]);

        $this->assertCount(1, $requests);
        $this->assertSame('PUT', $requests[0]['method']);
        $this->assertSame('https://openhab.example.test/rest/items/Weather_Php_Unit/state', $requests[0]['url']);
        $this->assertEquals('12.345', $requests[0]['options']['body']);
        $hasAuthHeader = false;
        foreach ($requests[0]['options']['headers'] ?? [] as $header) {
            if (str_starts_with($header, 'Authorization: Bearer test-token')) {
                $hasAuthHeader = true;
            }
        }

        $this->assertTrue($hasAuthHeader);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testThrowsOnErrorResponse(): void
    {
        $httpClient = new MockHttpClient(fn(): MockResponse => new MockResponse('', ['http_code' => 500]));

        $publisher = new OpenHabPublisher($this->config(), $httpClient, new NullLogger());

        $this->expectException(RuntimeException::class);
        $publisher->publish(['temperature-out' => 8.5]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testThrowsOnEmptyWorkload(): void
    {
        $httpClient = new MockHttpClient();
        $publisher = new OpenHabPublisher($this->config(), $httpClient, new NullLogger());

        $this->expectException(RuntimeException::class);
        $publisher->publish([]);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testDisabledWhenBaseUrlIsEmpty(): void
    {
        $config = new ParameterBag([
            'openhab' => [
                'base_url' => '',
                'token' => '',
                'mapping' => ['php-unit' => 'Weather_Php_Unit'],
            ],
        ]);
        $httpClient = new MockHttpClient(function (): never {
            self::fail('no HTTP request should be made when OPENHAB_URL is not set');
        });

        $publisher = new OpenHabPublisher($config, $httpClient, new NullLogger());

        // Must not throw, even with an empty workload — the sink is simply off.
        $publisher->publish([]);
        $publisher->publish(['php-unit' => 12.345]);
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

}
