<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenHabPublisher
{

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        ParameterBagInterface             $parameterBag,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $openhabLogger,
    )
    {
        $this->config = $parameterBag->get('openhab');
    }

    /**
     * @param array<string, float> $workload
     * @throws TransportExceptionInterface
     */
    public function publish(array $workload): void
    {
        $baseUrl = rtrim((string)($this->config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $this->openhabLogger->debug('openHAB sink disabled (OPENHAB_URL not set)');
            return;
        }

        if ($workload === []) {
            throw new RuntimeException('empty workload');
        }

        $token = (string)($this->config['token'] ?? '');
        $headers = ['Content-Type' => 'text/plain'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        foreach ($workload as $sensor => $value) {
            $item = $this->map($sensor);
            if ($item === false) {
                $this->openhabLogger->debug('skipping sensor, no item mapping', [$sensor => $value]);
                continue;
            }

            $this->openhabLogger->info('publishing to openHAB', [$item => $value]);
            $response = $this->httpClient->request('PUT', $baseUrl . '/rest/items/' . $item . '/state', [
                'headers' => $headers,
                'body' => (string)$value,
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300) {
                throw new RuntimeException(sprintf('openHAB responded with status %d for item %s', $statusCode, $item));
            }
        }
    }

    public function map(string $key): string|false
    {
        if (array_key_exists($key, $this->config['mapping'])) {
            return $this->config['mapping'][$key];
        }

        return false;
    }
}
