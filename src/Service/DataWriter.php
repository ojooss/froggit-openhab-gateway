<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use Throwable;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class DataWriter
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        protected Connection    $connection,
        protected InfluxService $influxWriter,
        #[Autowire(env: 'MYSQL_URL')]
        protected string        $databaseUrl = '',
        #[Autowire(env: 'MYSQL_TABLE')]
        protected string        $tablePrefix = 'data',
    ) {
    }

    public function tableName(string $collection): string
    {
        return $this->tablePrefix . '_' . $collection;
    }

    /**
     * @throws Exception
     */
    public function write(string $collection, DateTime $timestamp, ?string $device, string $attribute, float $value): void
    {
        if ($this->databaseUrl !== '') {
            $this->writeToDatabase($collection, $timestamp, $device, $attribute, $value);
        }

        // Independent of the MariaDB write above — InfluxService no-ops on its own if unconfigured.
        $this->influxWriter->write($collection, ['device' => $device ?? ''], [$attribute => $value], $timestamp);
    }

    /**
     * Assumes the table already exists — see SetupController, which creates and
     * maintains `<MYSQL_TABLE>_<collection>` tables ahead of time rather than this
     * method creating them lazily on first write.
     *
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    private function writeToDatabase(string $collection, DateTime $timestamp, ?string $device, string $attribute, float $value): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) {
            throw new InvalidArgumentException('Invalid collection name: ' . $collection);
        }

        $table = $this->tableName($collection);

        $sql = 'INSERT INTO ' . $table . ' ' .
            '(timestamp, ' . ($device !== null ? 'device, ' : '') . 'attribute, value) ' .
            'VALUES (:timestamp, ' . ($device !== null ? ':device, ' : '') . ':attribute, :value) ' .
            'ON DUPLICATE KEY UPDATE value = :value';
        $parameter = [
            'timestamp' => $timestamp->format('Y-m-d H:i:s'),
            'attribute' => $attribute,
            'value' => $value,
        ];
        if ($device !== null) {
            $parameter['device'] = $device;
        }

        $this->connection->executeStatement($sql, $parameter);
    }

    /**
     * @throws Throwable
     */
    public function flush(): void
    {
        $this->influxWriter->flush(throwOnFailure: true);
    }

    /**
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    public function make(string $collection, ?string $device): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) {
            throw new InvalidArgumentException('Invalid collection name: ' . $collection);
        }

        $table = $this->tableName($collection);
        $deviceCol = $device !== null ? 'device VARCHAR(255), ' : '';
        $devicePk  = $device !== null ? 'device, ' : '';

        // Single statement: CREATE TABLE with inline PRIMARY KEY and indexes.
        // DDL in MariaDB causes an implicit commit, so splitting into multiple
        // statements would leave a partially created table on failure.
        $this->connection->executeStatement(
            'CREATE TABLE ' . $table . ' ('
            . 'timestamp TIMESTAMP, '
            . $deviceCol
            . 'attribute VARCHAR(255), '
            . 'value FLOAT, '
            . 'PRIMARY KEY (timestamp, ' . $devicePk . 'attribute), '
            . 'INDEX idx_' . $table . '_ts (timestamp), '
            . 'INDEX idx_' . $table . '_dev_attr_ts (' . $devicePk . 'attribute, timestamp)'
            . ')'
        );
    }

    /**
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    public function tableExists(string $collection): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) {
            throw new InvalidArgumentException('Invalid collection name: ' . $collection);
        }

        $table = $this->tableName($collection);

        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        ) > 0;
    }

    /**
     * The columns actually present on `<MYSQL_TABLE>_<collection>`, as currently defined
     * in the database — used by SetupController to detect drift against
     * expectedColumns().
     *
     * @return array<string, string> column name => lowercase data type
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    public function actualColumns(string $collection): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) {
            throw new InvalidArgumentException('Invalid collection name: ' . $collection);
        }

        $table = $this->tableName($collection);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT column_name, data_type FROM information_schema.columns ' .
            'WHERE table_schema = DATABASE() AND table_name = :table ORDER BY ordinal_position',
            ['table' => $table]
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['column_name']] = strtolower((string)$row['data_type']);
        }

        return $columns;
    }

    /**
     * The columns make() would create for $device, in the same shape returned
     * by actualColumns() — the baseline SetupController compares against.
     *
     * @return array<string, string> column name => lowercase data type
     */
    public function expectedColumns(?string $device): array
    {
        $columns = ['timestamp' => 'timestamp'];
        if ($device !== null) {
            $columns['device'] = 'varchar';
        }

        $columns['attribute'] = 'varchar';
        $columns['value'] = 'float';

        return $columns;
    }
}
