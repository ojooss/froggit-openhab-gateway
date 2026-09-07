<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeInterface;
use InfluxDB2\Client;
use InfluxDB2\FluxRecord;
use InfluxDB2\FluxTable;
use InfluxDB2\Model\WritePrecision;
use InfluxDB2\Point;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Throwable;

/**
 * Minimal wrapper to write/read points to/from InfluxDB v2.
 *
 * Configuration is provided via Symfony's ParameterBag under parameters.influx
 * (services.yaml), typically populated from environment variables:
 *  - INFLUXDB_URL
 *  - INFLUXDB_TOKEN
 *  - INFLUXDB_ORG
 *  - INFLUXDB_BUCKET
 */
class InfluxService
{
    private readonly bool $enabled;

    private readonly ?Client $client;

    private readonly string $bucket;

    private readonly string $org;

    /** @var Point[] */
    private array $buffer = [];

    public function __construct(ParameterBagInterface $parameterBag, private readonly LoggerInterface $logger)
    {
        $influx = (array)($parameterBag->has('influx') ? $parameterBag->get('influx') : []);

        $url = (string)($influx['url'] ?? '');
        $this->enabled = $url !== '';

        if (!$this->enabled) {
            $this->client = null;
            $this->org = '';
            $this->bucket = '';
            return;
        }

        $token = (string)$influx['token'];
        $this->org = (string)$influx['org'];
        $this->bucket = (string)$influx['bucket'];

        $this->client = new Client([
            'url' => $url,
            'token' => $token,
            'org' => $this->org,
            'bucket' => $this->bucket,
        ]);

        // Safety-net only: callers are expected to call flush(throwOnFailure: true)
        // explicitly once they're done writing (see DataWriter::flush()) so failures
        // can be reported while the response is still being built. This shutdown
        // flush must never throw — by then the response may already be sent, and an
        // uncaught exception here would crash the whole PHP process.
        register_shutdown_function(/**
         * @throws Throwable
         */ fn() => $this->flush());
    }

    /**
     * @param array<string,string|int|float|bool|null> $tags
     * @param array<string,string|int|float|bool|null> $fields
     */
    public function write(string $measurement, array $tags, array $fields, DateTimeInterface $timestamp): void
    {
        if (!$this->enabled) {
            return;
        }

        $fields = array_filter($fields, static fn(string|int|float|bool|null $v): bool => $v !== null);
        if ($fields === []) {
            return;
        }

        $point = Point::measurement($measurement);

        foreach ($tags as $key => $value) {
            if ($value === null) { continue; }

            $point = $point->addTag($key, (string)$value);
        }

        foreach ($fields as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $point = $point->addField((string)$key, (float)$value);
            } elseif (is_bool($value)) {
                $point = $point->addField((string)$key, $value);
            } else {
                $point = $point->addField((string)$key, (string)$value);
            }
        }

        $point = $point->time($timestamp);
        $this->buffer[] = $point;
    }

    /**
     * @param bool $throwOnFailure Pass true to let a write failure propagate (used by
     *                             the explicit, synchronous flush from DataWriter::flush()
     *                             so the caller can report it as a failed sink). The
     *                             automatic shutdown-time flush always uses the default
     *                             (false) so it can never crash the process.
     * @throws Throwable
     */
    public function flush(bool $throwOnFailure = false): void
    {
        if (!$this->enabled || $this->buffer === []) {
            return;
        }

        $points = $this->buffer;
        $this->buffer = [];

        try {
            $writeApi = $this->client->createWriteApi();
            $writeApi->write($points, WritePrecision::NS, $this->bucket, $this->org);
            $writeApi->close();
        } catch (Throwable $throwable) {
            $this->logger->error('InfluxDB write failed: ' . $throwable->getMessage(), ['points' => count($points)]);
            if ($throwOnFailure) {
                throw $throwable;
            }
        }
    }

    /**
     * Reads data from InfluxDB (measurement = $collection), adapted to the return
     * format of DataReader::read().
     *
     * Tags: device; Fields: {attribute_name}=value; Timestamp: _time
     *
     * @return array<int,array{timestamp:string,device:string,attribute:string,value:float}>
     */
    public function read(
        string $collection,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $device,
        ?string $attribute,
        int $limit,
        string $order
    ): array
    {
        if (!$this->enabled) {
            return [];
        }

        $start = $from->format(DateTimeInterface::RFC3339_EXTENDED);
        $stop = $to->format(DateTimeInterface::RFC3339_EXTENDED);

        $filters = [];
        $filters[] = 'r._measurement == ' . self::fluxString($collection);
        if ($device !== null && $device !== '') {
            $filters[] = 'r.device == ' . self::fluxString($device);
        }

        if ($attribute !== null && $attribute !== '') {
            $filters[] = 'r._field == ' . self::fluxString($attribute);
        }

        $desc = ($order === 'DESC') ? 'true' : 'false';

        $flux = 'from(bucket: ' . self::fluxString($this->bucket) . ')'
            . ' |> range(start: time(v: ' . self::fluxString($start) . '), stop: time(v: ' . self::fluxString($stop) . '))'
            . ' |> filter(fn: (r) => ' . implode(' and ', $filters) . ')'
            . ' |> sort(columns: ["_time"], desc: ' . $desc . ')';
        if ($limit > 0) {
            $flux .= ' |> limit(n: ' . $limit . ')';
        }

        try {
            $queryApi = $this->client->createQueryApi();
            /** @var FluxTable[] $tables */
            $tables = $queryApi->query($flux, $this->org);

            $result = [];
            foreach ($tables as $table) {
                /** @var FluxRecord $record */
                foreach ($table->records as $record) {
                    $time = $record->getTime();
                    $values = $record->values;
                    $result[] = [
                        'timestamp' => $time instanceof DateTimeInterface ? $time->format('Y-m-d H:i:s') : (string)$time,
                        'device' => isset($values['device']) ? (string)$values['device'] : '',
                        'attribute' => (string)$record->getField(),
                        'value' => (float)$record->getValue(),
                    ];
                }
            }

            $this->client->close();

            return $result;
        } catch (Throwable $throwable) {
            $this->logger->error('InfluxDB read failed: ' . $throwable->getMessage());
            return [];
        }
    }

    private static function fluxString(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
}
