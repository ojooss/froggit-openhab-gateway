<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataWriter;
use App\Service\InfluxService;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;

final class DataWriterTest extends TestCase
{

    /**
     * @throws Exception
     */
    public function testSkipsMariaDbWhenDatabaseUrlIsEmpty(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $influx = $this->createMock(InfluxService::class);
        $influx->expects($this->once())->method('write');

        $dataWriter = new DataWriter($connection, $influx, '');
        $dataWriter->write('froggit', new DateTime('now'), null, 'php-unit', 12.3);
    }

    /** @noinspection SqlNoDataSourceInspection */
    /**
     * @throws Exception
     */
    public function testWritesToMariaDbWhenDatabaseUrlIsConfigured(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(self::stringContains('INSERT INTO data_froggit '));

        $influx = $this->createMock(InfluxService::class);
        $influx->expects($this->once())->method('write');

        $dataWriter = new DataWriter($connection, $influx, 'mysql://user:pass@mariadb:3306/db');
        $dataWriter->write('froggit', new DateTime('now'), null, 'php-unit', 12.3);
    }

    /** @noinspection SqlNoDataSourceInspection */
    /**
     * @throws Exception
     */
    public function testUsesConfiguredMysqlTablePrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(self::stringContains('INSERT INTO weather_froggit '));

        $influx = $this->createMock(InfluxService::class);
        $influx->expects($this->once())->method('write');

        $dataWriter = new DataWriter($connection, $influx, 'mysql://user:pass@mariadb:3306/db', 'weather');
        $dataWriter->write('froggit', new DateTime('now'), null, 'php-unit', 12.3);
    }

}
