<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\FroggitService;
use App\Service\OpenHabPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class FroggitController extends AbstractController
{

    public function __construct(
        private readonly FroggitService    $froggitService,
        private readonly OpenHabPublisher  $openHabPublisher,
        private readonly LoggerInterface   $froggitLogger,
        private readonly LoggerInterface   $openhabLogger,
    )
    {
    }

    /**
     * Each configured sink (MariaDB, InfluxDB, openHAB) is attempted and logged
     * independently — a disabled sink (empty URL, see DataWriter/InfluxService/
     * OpenHabPublisher) is skipped and does not count as a failure. "All or
     * nothing": the response is only 200 "ok" if every sink actually attempted
     * succeeded; if even one fails, 500 is returned
     * (see PLAN.md, section 7).
     */
    #[Route('/froggit', name: 'app_froggit')]
    #[Route('/data/report/', name: 'app_froggit_data_report')]
    public function index(Request $request): JsonResponse
    {
        try {
            $parameters = array_merge($request->query->all(), $request->request->all());
            if ($parameters === []) {
                throw new BadRequestException('no parameter given');
            }

            $workload = $this->froggitService->handle($parameters);
        } catch (BadRequestException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (Throwable $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $failedSinks = [];

        try {
            $this->froggitService->persist($workload);
        } catch (Throwable $throwable) {
            $this->froggitLogger->error('database write failed: ' . $throwable->getMessage(), ['exception' => $throwable]);
            $failedSinks[] = 'database';
        }

        try {
            $this->froggitService->flush();
        } catch (Throwable $throwable) {
            $this->froggitLogger->error('influx write failed: ' . $throwable->getMessage(), ['exception' => $throwable]);
            $failedSinks[] = 'influx';
        }

        try {
            $this->openHabPublisher->publish($workload);
        } catch (Throwable $throwable) {
            $this->openhabLogger->error('publish failed: ' . $throwable->getMessage(), ['exception' => $throwable]);
            $failedSinks[] = 'openhab';
        }

        if ($failedSinks !== []) {
            return $this->json(['message' => 'sink(s) failed: ' . implode(', ', $failedSinks)], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['message' => 'ok'], Response::HTTP_OK);
    }

}
