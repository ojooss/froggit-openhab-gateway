<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DataWriter;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SetupController extends AbstractController
{
    /**
     * Every collection DataWriter::write() is ever called with, mapped to the
     * $device value it's called with (null means the collection has no
     * per-device column — see DataWriter::make()). Extend this list whenever a
     * new collection starts being written.
     *
     * @var array<string, string|null>
     */
    private const array COLLECTIONS = [
        'froggit' => null,
    ];

    public function __construct(
        private readonly DataWriter $dataWriter,
        #[Autowire(env: 'MYSQL_URL')]
        private readonly string     $databaseUrl = '',
    ) {
    }

    /**
     * Creates any missing `<MYSQL_TABLE>_<collection>` table. For tables that already
     * exist, compares their actual columns against what make() would create
     * today and reports any drift — it does not attempt to migrate the table
     * itself; that's left for a future migration mechanism once real drift
     * shows up here.
     *
     * @throws Exception
     */
    #[Route('/setup', name: 'app_setup')]
    public function index(): JsonResponse
    {
        if ($this->databaseUrl === '') {
            return $this->json(['message' => 'MariaDB is not configured (MYSQL_URL empty), nothing to set up']);
        }

        $report = [];
        foreach (self::COLLECTIONS as $collection => $device) {
            $report[$collection] = $this->setupCollection($collection, $device);
        }

        return $this->json(['collections' => $report]);
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function setupCollection(string $collection, ?string $device): array
    {
        if (!$this->dataWriter->tableExists($collection)) {
            $this->dataWriter->make($collection, $device);
            return ['status' => 'created'];
        }

        $expected = $this->dataWriter->expectedColumns($device);
        $actual = $this->dataWriter->actualColumns($collection);

        $missing = array_diff_key($expected, $actual);
        $extra = array_diff_key($actual, $expected);
        $mismatched = [];
        foreach (array_intersect_key($expected, $actual) as $name => $type) {
            if ($actual[$name] !== $type) {
                $mismatched[$name] = ['expected' => $type, 'actual' => $actual[$name]];
            }
        }

        if ($missing === [] && $extra === [] && $mismatched === []) {
            return ['status' => 'ok'];
        }

        return [
            'status' => 'drift',
            'missing_columns' => $missing,
            'extra_columns' => $extra,
            'mismatched_columns' => $mismatched,
        ];
    }
}
