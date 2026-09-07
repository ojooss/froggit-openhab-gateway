<?php

declare(strict_types=1);

namespace App\Service;

use Throwable;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class FroggitService
{

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        ParameterBagInterface            $parameterBag,
        private readonly LoggerInterface $froggitLogger,
        private readonly DataWriter      $dataWriter,
    )
    {
        $this->config = $parameterBag->get('froggit');
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, float>
     */
    public function handle(array $parameters): array
    {
        $this->froggitLogger->debug('parameters', [$parameters]);
        if ($parameters === []) {
            throw new RuntimeException('empty parameters');
        }

        $workload = [];
        foreach ($parameters as $key => $value) {
            if ($index = $this->map($key)) {
                $workload[$index] = $this->convert($key, $value);
            } else {
                $this->froggitLogger->debug('skipping parameter', [$key => $value]);
            }
        }

        $this->froggitLogger->info('workload', [$workload]);
        return $workload;
    }

    public function map(string $key): mixed
    {
        if (array_key_exists($key, $this->config['mapping'])) {
            return $this->config['mapping'][$key];
        }

        return false;
    }

    /**
     * @throws Exception
     * @throws \Exception
     * @noinspection SqlResolve
     * @param array<string, float> $workload
     */
    public function persist(array $workload): void
    {
        if ($workload === []) {
            throw new RuntimeException('empty workload');
        }

        $now = new DateTime('now');
        $now->setTimezone(new DateTimeZone('UTC'));
        foreach ($workload as $sensor => $value) {
            $this->dataWriter->write(
                'froggit',
                $now,
                null,
                $sensor,
                $value
            );
        }
    }

    /**
     * Sends the InfluxDB points buffered by persist() calls. Separate from persist()
     * so the controller can treat MariaDB and InfluxDB as two independently
     * reportable sinks (see FroggitController::index()).
     *
     * @throws Throwable
     */
    public function flush(): void
    {
        $this->dataWriter->flush();
    }

    private function convert(string $key, mixed $value): float
    {
        if (!$this->config['convert_enabled']) {
            return (float)$value;
        }

        $converter = $this->config['convert'][$key] ?? null;

        return match ($converter) {
            'convertWind'          => (float)$value * 0.44704,            // mph → m/s
            'convertTemperature'   => round(((float)$value - 32) * (5 / 9), 1), // °F → °C
            'convertBarometer'     => (float)(int)round((float)$value * 33.862), // inHg → hPa
            'convertRain'          => (float)(int)round((float)$value * 25.4),   // in → mm
            'convertSolarRadiation'=> (float)(int)round((float)$value * 130),    // W/m² → lux
            default                => (float)$value,
        };
    }
}
