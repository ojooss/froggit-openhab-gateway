<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataReader;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DataReaderTest extends TestCase
{

    /**
     * @throws Exception
     */
    public function testReturnsEmptyWhenDatabaseUrlIsEmpty(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');
        $connection->expects($this->never())->method('fetchAllAssociative');

        $dataReader = new DataReader($connection, '');

        $this->assertSame(
            [],
            $dataReader->read('froggit', new DateTime('-1 hour'), new DateTime('now'), null, null, 10, 'DESC')
        );
    }

    /** @noinspection SqlNoDataSourceInspection */
    /**
     * @throws Exception
     */
    public function testReadsRowsWithoutDeviceColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(self::stringContains('information_schema.columns'))
            ->willReturn(0);

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('SELECT timestamp, attribute, value FROM data_froggit'),
                    self::stringContains('ORDER BY timestamp DESC'),
                    self::stringContains('LIMIT 10')
                )
            )
            ->willReturn([
                ['timestamp' => '2026-09-07 10:00:00', 'attribute' => 'php-unit', 'value' => '12.3'],
            ]);

        $dataReader = new DataReader($connection, 'mysql://user:pass@mariadb:3306/db');
        $rows = $dataReader->read('froggit', new DateTime('-1 hour'), new DateTime('now'), null, null, 10, 'DESC');

        $this->assertSame([
            ['timestamp' => '2026-09-07 10:00:00', 'device' => '', 'attribute' => 'php-unit', 'value' => 12.3],
        ], $rows);
    }

    /** @noinspection SqlNoDataSourceInspection */
    /**
     * @throws Exception
     */
    public function testFiltersByDeviceWhenColumnExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                self::logicalAnd(
                    self::stringContains('SELECT timestamp, device, attribute, value FROM data_froggit'),
                    self::stringContains('AND device = :device')
                ),
                self::callback(static fn(array $params): bool => $params['device'] === 'sensor-1')
            )
            ->willReturn([]);

        $dataReader = new DataReader($connection, 'mysql://user:pass@mariadb:3306/db');
        $dataReader->read('froggit', new DateTime('-1 hour'), new DateTime('now'), 'sensor-1', null, 10, 'ASC');
    }

    /**
     * @throws Exception
     */
    public function testUsesConfiguredMysqlTablePrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(self::stringContains('FROM weather_froggit'))
            ->willReturn([]);

        $dataReader = new DataReader($connection, 'mysql://user:pass@mariadb:3306/db', 'weather');
        $dataReader->read('froggit', new DateTime('-1 hour'), new DateTime('now'), null, null, 10, 'DESC');
    }

    /**
     * @throws Exception
     */
    public function testRejectsInvalidCollectionName(): void
    {
        $connection = $this->createStub(Connection::class);

        $dataReader = new DataReader($connection, 'mysql://user:pass@mariadb:3306/db');

        $this->expectException(InvalidArgumentException::class);
        $dataReader->read('Invalid Name', new DateTime('-1 hour'), new DateTime('now'), null, null, 10, 'DESC');
    }

}
