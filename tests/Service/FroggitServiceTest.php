<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataWriter;
use App\Service\FroggitService;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FroggitServiceTest extends KernelTestCase
{

    /**
     * @throws Exception
     */
    public function testIgnoreUnknown(): void
    {
        /** @var FroggitService $froggitService */
        $froggitService = self::getContainer()->get(FroggitService::class);
        $workload = $froggitService->handle([
            'phpunit' => '23.1',  // should be handled
            'phptest' => '23.1',  // should be ignored
        ]);
        $this->assertCount(1, $workload);
    }

    /**
     * @throws Exception
     */
    public function testDefault(): void
    {
        /** @var FroggitService $froggitService */
        $froggitService = self::getContainer()->get(FroggitService::class);
        $workload = $froggitService->handle([
            'PASSKEY' => 'ACA4D56B4A00A25083AF04701AC8FACF',
            'stationtype' => 'EasyWeatherV1.5.9',
            'dateutc' => '2022-05-29 11:10:21',
            'tempinf' => 74.8,
            'humidityin' => 42,
            'baromrelin' => 30.059,
            'baromabsin' => 29.106,
            'tempf' => 47.3,
            'humidity' => 91,
            'winddir' => 85,
            'winddir_avg10m' => 85,
            'windspeedmph' => 0.7,
            'windspdmph_avg10m' => 0.9,
            'windgustmph' => 2.2,
            'maxdailygust' => 6.9,
            'rainratein' => 0.118,
            'eventrainin' => 0.272,
            'hourlyrainin' => 0.220,
            'dailyrainin' => 0.291,
            'weeklyrainin' => 0.291,
            'monthlyrainin' => 1.921,
            'yearlyrainin' => 14.000,
            'solarradiation' => 39.88,
            'uv' => 0,
            'temp1f' => 64.4,
            'humidity1' => 41,
            'temp2f' => 60.8,
            'humidity2' => 58,
            'temp3f' => 69.6,
            'humidity3' => 47,
            'soilmoisture2' => 28,
            'wh65batt' => 0,
            'wh25batt' => 0,
            'batt1' => 0,
            'batt2' => 0,
            'batt3' => 0,
            'soilbatt2' => 1.4,
            'freq' => '868M',
            'model' => 'HP1000SE-PRO_Pro_V1.6.4',
        ]);
        ksort($workload);
        $this->assertEquals([
            'barometer' => 1018,
            'humidity-basement' => 47,
            'humidity-garage' => 58,
            'humidity-living' => 42,
            'humidity-out' => 91,
            'humidity-roof' => 41,
            'rain-daily' => 7,
            'rain-event' => 7,
            'rain-hourly' => 6,
            'rain-rate' => 0.118,
            'soil-moisture-2' => 28,
            'temperature-basement' => 20.9,
            'temperature-garage' => 16.0,
            'temperature-living' => 23.8,
            'temperature-out' => 8.5,
            'temperature-roof' => 18.0,
            'uv' => 0,
            'wind-direction-avg10m' => 85,
            'wind-gust' => 0.983488,
            'wind-gust-max-day' => 3.084576,
            'wind-speed-avg10m' => 0.402336,
            'solar-radiation' => 5184,
        ], $workload);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    public function testPersist(): void
    {
        /** @var FroggitService $froggitService */
        $froggitService = self::getContainer()->get(FroggitService::class);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        /** @var DataWriter $dataWriter */
        $dataWriter = self::getContainer()->get(DataWriter::class);
        $table = $dataWriter->tableName('froggit');
        try {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        } catch (\Doctrine\DBAL\Exception $exception) {
            if (!str_contains($exception->getMessage(), 'table or view not found')) {
                throw $exception;
            }
        }

        $froggitService->persist([
            'phpunit' => 12.3,
            'test' => 1
        ]);

        $result = $connection->executeQuery('SELECT * FROM ' . $table);
        $this->assertEquals(2, $result->rowCount());
    }

}
