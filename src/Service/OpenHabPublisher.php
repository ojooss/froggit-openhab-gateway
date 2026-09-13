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
     * @return array<string, int> sensor => HTTP status of failed items; empty = all ok or sink disabled
     * @throws TransportExceptionInterface
     */
    public function publish(array $workload): array
    {
        $baseUrl = rtrim((string)($this->config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $this->openhabLogger->debug('openHAB sink disabled (OPENHAB_URL not set)');
            return [];
        }

        if ($workload === []) {
            throw new RuntimeException('empty workload');
        }

        $token = (string)($this->config['token'] ?? '');
        $headers = ['Content-Type' => 'text/plain'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $itemFailures = [];
        foreach ($workload as $sensor => $value) {
            $mapping = $this->map($sensor);
            if ($mapping === false) {
                $this->openhabLogger->info('skipping sensor, no item mapping', [$sensor => $value]);
                continue;
            }

            if (!$mapping['enabled']) {
                $this->openhabLogger->debug('skipping sensor, disabled in mapping config', [$sensor => $mapping['item']]);
                continue;
            }

            $item = $mapping['item'];
            try {
                $this->openhabLogger->info('publishing to openHAB', [$item => $value]);
                $response = $this->httpClient->request('PUT', $baseUrl . '/rest/items/' . $item . '/state', [
                    'headers' => $headers,
                    'body' => (string)$value,
                ]);
                $statusCode = $response->getStatusCode();
            } catch (TransportExceptionInterface $transportException) {
                $this->openhabLogger->error(
                    sprintf('openHAB unreachable while publishing sensor %s (item %s), aborting remaining items', $sensor, $item),
                    ['exception' => $transportException]
                );
                throw $transportException;
            }

            if ($statusCode >= 300) {
                $this->openhabLogger->warning(
                    sprintf('openHAB responded with status %d for item %s', $statusCode, $item),
                    ['sensor' => $sensor, 'item' => $item, 'status' => $statusCode]
                );
                $itemFailures[$sensor] = $statusCode;
            }
        }

        if ($itemFailures !== []) {
            $this->openhabLogger->warning(
                sprintf('openHAB publish completed with %d of %d item(s) failing', count($itemFailures), count($workload)),
                ['failures' => $itemFailures]
            );
        }

        return $itemFailures;
    }

    /**
     * @return array{item: string, enabled: bool}|false
     */
    public function map(string $key): array|false
    {
        if (!array_key_exists($key, $this->config['mapping'])) {
            return false;
        }

        $entry = $this->config['mapping'][$key];

        return [
            'item' => (string)$entry['item'],
            'enabled' => (bool)($entry['enabled'] ?? true),
        ];
    }
}
