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
                    'php-unit' => ['item' => 'Weather_Php_Unit'],
                    'temperature-out' => ['item' => 'Weather_Temperature_Out'],
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
        $failures = $publisher->publish([
            'php-unit' => 12.345,
            'unknown-sensor' => 1.0,
        ]);

        $this->assertSame([], $failures);
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
    public function testReturnsPerItemFailureInsteadOfThrowingOnErrorResponse(): void
    {
        $httpClient = new MockHttpClient(fn(): MockResponse => new MockResponse('', ['http_code' => 500]));

        $publisher = new OpenHabPublisher($this->config(), $httpClient, new NullLogger());

        $failures = $publisher->publish(['temperature-out' => 8.5]);

        $this->assertSame(['temperature-out' => 500], $failures);
        $this->assertSame(1, $httpClient->getRequestsCount());
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
                'mapping' => ['php-unit' => ['item' => 'Weather_Php_Unit']],
            ],
        ]);
        $httpClient = new MockHttpClient(function (): never {
            self::fail('no HTTP request should be made when OPENHAB_URL is not set');
        });

        $publisher = new OpenHabPublisher($config, $httpClient, new NullLogger());

        // Must not throw, even with an empty workload — the sink is simply off.
        $this->assertSame([], $publisher->publish([]));
        $this->assertSame([], $publisher->publish(['php-unit' => 12.345]));
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testContinuesRemainingItemsAfterOneItemGets404OrOtherStatusFailure(): void
    {
        $config = new ParameterBag([
            'openhab' => [
                'base_url' => 'https://openhab.example.test',
                'token' => 'test-token',
                'mapping' => [
                    'php-unit' => ['item' => 'Weather_Php_Unit'],
                    'temperature-out' => ['item' => 'Weather_Temperature_Out'],
                    'barometer' => ['item' => 'Weather_Barometer'],
                ],
            ],
        ]);

        $responses = [
            new MockResponse('', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 200]),
        ];
        $httpClient = new MockHttpClient($responses);

        $publisher = new OpenHabPublisher($config, $httpClient, new NullLogger());
        $failures = $publisher->publish([
            'php-unit' => 1.0,
            'temperature-out' => 2.0,
            'barometer' => 3.0,
        ]);

        $this->assertSame(3, $httpClient->getRequestsCount());
        $this->assertSame(['temperature-out' => 404], $failures);
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testAbortsRemainingItemsAndThrowsOnTransportFailure(): void
    {
        $config = new ParameterBag([
            'openhab' => [
                'base_url' => 'https://openhab.example.test',
                'token' => 'test-token',
                'mapping' => [
                    'php-unit' => ['item' => 'Weather_Php_Unit'],
                    'temperature-out' => ['item' => 'Weather_Temperature_Out'],
                ],
            ],
        ]);

        $responses = [
            new MockResponse('', ['error' => 'simulated transport failure']),
            new MockResponse('', ['http_code' => 200]),
        ];
        $httpClient = new MockHttpClient($responses);

        $publisher = new OpenHabPublisher($config, $httpClient, new NullLogger());

        $this->expectException(TransportExceptionInterface::class);
        try {
            $publisher->publish([
                'php-unit' => 1.0,
                'temperature-out' => 2.0,
            ]);
        } finally {
            $this->assertSame(1, $httpClient->getRequestsCount());
        }
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function testSkipsSensorDisabledInMappingWithoutMakingHttpCall(): void
    {
        $config = new ParameterBag([
            'openhab' => [
                'base_url' => 'https://openhab.example.test',
                'token' => 'test-token',
                'mapping' => [
                    'php-unit' => ['item' => 'Weather_Php_Unit', 'enabled' => false],
                    'temperature-out' => ['item' => 'Weather_Temperature_Out'],
                ],
            ],
        ]);

        $httpClient = new MockHttpClient(fn(): MockResponse => new MockResponse('', ['http_code' => 200]));

        $publisher = new OpenHabPublisher($config, $httpClient, new NullLogger());
        $failures = $publisher->publish([
            'php-unit' => 1.0,
            'temperature-out' => 2.0,
        ]);

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertSame([], $failures);
    }

}
