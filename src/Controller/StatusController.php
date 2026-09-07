<?php

declare(strict_types=1);

namespace App\Controller;

use BretRZaun\StatusPage\Check\CallbackCheck;
use BretRZaun\StatusPage\Check\DoctrineConnectionCheck;
use BretRZaun\StatusPage\Result;
use BretRZaun\StatusPage\StatusChecker;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

class StatusController extends AbstractController
{

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private readonly Connection          $connection,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string              $projectDir,
        #[Autowire(env: 'MYSQL_URL')]
        private readonly string              $databaseUrl = '',
        #[Autowire(env: 'INFLUXDB_URL')]
        private readonly string              $influxUrl = '',
        #[Autowire(env: 'OPENHAB_URL')]
        private readonly string              $openHabUrl = '',
        #[Autowire(env: 'OPENHAB_TOKEN')]
        private readonly string              $openHabToken = '',
    )
    {
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    #[Route('/status', name: 'app_status')]
    public function index(): Response
    {
        $checker = new StatusChecker();

        // Each check is only registered for a sink that's actually configured — an
        // unconfigured sink is intentionally disabled (see AGENTS.md), not broken.
        if ($this->databaseUrl !== '') {
            $checker->addCheck(new DoctrineConnectionCheck('MariaDB', $this->connection));
        }

        if ($this->influxUrl !== '') {
            $checker->addCheck(new CallbackCheck('InfluxDB', function (Result $result): void {
                $this->checkHttpEndpoint($result, rtrim($this->influxUrl, '/') . '/health');
            }));
        }

        if ($this->openHabUrl !== '') {
            $checker->addCheck(new CallbackCheck('openHAB', function (Result $result): void {
                $headers = [];
                if ($this->openHabToken !== '') {
                    $headers['Authorization'] = 'Bearer ' . $this->openHabToken;
                }

                $this->checkHttpEndpoint($result, rtrim($this->openHabUrl, '/') . '/rest', $headers);
            }));
        }

        $checker->check();

        $loader = new FilesystemLoader($this->projectDir . '/vendor/bretrzaun/statuspage/resources/views');
        $twig = new Environment($loader, ['autoescape' => false]);

        $content = $twig->render('bootstrap_5.html.twig', [
            'results' => $checker->getResults(),
            'title' => sprintf('Froggit openHAB Gateway v%s - Status', $this->getAppVersion()),
        ]);

        return new Response(
            $content,
            $checker->hasErrors() ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK
        );
    }

    private function getAppVersion(): string
    {
        $composer = json_decode(
            file_get_contents($this->projectDir . '/composer.json') ?: '{}',
            true,
        );

        return $composer['version'] ?? 'unknown';
    }

    /**
     * @param array<string, string> $headers
     */
    private function checkHttpEndpoint(Result $result, string $url, array $headers = []): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['headers' => $headers]);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 300) {
                $result->setError(sprintf('%s responded with HTTP %d', $url, $statusCode));
            }
        } catch (Throwable $throwable) {
            $result->setError($throwable->getMessage());
        }
    }

}
