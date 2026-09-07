<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use App\Form\DataViewSearchType;
use App\Form\Model\DataViewSearch;
use App\Service\DataReader;
use App\Service\InfluxService;
use Doctrine\DBAL\Exception as DbalException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Lets a user pick a time range and one configured sink (MariaDB or InfluxDB)
 * and shows the matching `<collection>` readings as an HTML table. Read-only
 * counterpart to FroggitController — same sinks, opposite direction.
 */
class DataViewController extends AbstractController
{
    private const int MAX_LIMIT = 1000;

    public function __construct(
        private readonly DataReader $dataReader,
        private readonly InfluxService $influxService,
        #[Autowire(env: 'MYSQL_URL')]
        private readonly string $databaseUrl = '',
        #[Autowire(env: 'INFLUXDB_URL')]
        private readonly string $influxUrl = '',
    ) {
    }

    #[Route('/data/view', name: 'app_data_view', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $availableSinks = [];
        if ($this->databaseUrl !== '') {
            $availableSinks['mariadb'] = 'MariaDB';
        }

        if ($this->influxUrl !== '') {
            $availableSinks['influx'] = 'InfluxDB';
        }

        $search = new DataViewSearch();
        $form = $this->createForm(DataViewSearchType::class, $search, [
            'available_sinks' => $availableSinks,
        ]);
        $form->handleRequest($request);

        $rows = null;
        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $rows = $this->fetch($search);
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }

        return $this->render('data_view.html.twig', [
            'form' => $form,
            'available_sinks' => $availableSinks,
            'search' => $search,
            'rows' => $rows,
            'error' => $error,
        ]);
    }

    /**
     * @return array<int,array{timestamp:string,device:string,attribute:string,value:float}>
     * @throws DbalException
     */
    private function fetch(DataViewSearch $search): array
    {
        if ($search->sink === null || !$search->from instanceof DateTimeImmutable || !$search->to instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('sink, "from" and "to" are required');
        }

        return $search->sink === 'mariadb'
            ? $this->dataReader->read($search->collection, $search->from, $search->to, null, null, self::MAX_LIMIT, 'DESC')
            : $this->influxService->read($search->collection, $search->from, $search->to, null, null, self::MAX_LIMIT, 'DESC');
    }
}
