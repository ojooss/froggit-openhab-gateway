<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads back what DataWriter::write() persisted into `<MYSQL_TABLE>_<collection>`.
 * Mirrors InfluxService::read()'s return format so both sinks can be displayed
 * the same way (see DataViewController).
 */
readonly class DataReader
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        protected Connection $connection,
        #[Autowire(env: 'MYSQL_URL')]
        protected string     $databaseUrl = '',
        #[Autowire(env: 'MYSQL_TABLE')]
        protected string     $tablePrefix = 'data',
    ) {
    }

    private function tableName(string $collection): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $collection)) {
            throw new InvalidArgumentException('Invalid collection name: ' . $collection);
        }

        return $this->tablePrefix . '_' . $collection;
    }

    /**
     * @return array<int,array{timestamp:string,device:string,attribute:string,value:float}>
     * @throws Exception
     */
    public function read(
        string $collection,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $device,
        ?string $attribute,
        int $limit,
        string $order
    ): array {
        if ($this->databaseUrl === '') {
            return [];
        }

        $table = $this->tableName($collection);
        $hasDevice = $this->hasDeviceColumn($table);

        $columns = 'timestamp, ' . ($hasDevice ? 'device, ' : '') . 'attribute, value';
        $sql = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE timestamp BETWEEN :from AND :to';
        $parameters = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($hasDevice && $device !== null && $device !== '') {
            $sql .= ' AND device = :device';
            $parameters['device'] = $device;
        }

        if ($attribute !== null && $attribute !== '') {
            $sql .= ' AND attribute = :attribute';
            $parameters['attribute'] = $attribute;
        }

        $sql .= ' ORDER BY timestamp ' . ($order === 'DESC' ? 'DESC' : 'ASC');
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $parameters);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'timestamp' => (string)$row['timestamp'],
                'device' => $hasDevice ? (string)$row['device'] : '',
                'attribute' => (string)$row['attribute'],
                'value' => (float)$row['value'],
            ];
        }

        return $result;
    }

    /**
     * @throws Exception
     * @noinspection SqlNoDataSourceInspection
     */
    private function hasDeviceColumn(string $table): bool
    {
        return (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns ' .
            'WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => $table, 'column' => 'device']
        ) > 0;
    }
}
