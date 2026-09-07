<?php

declare(strict_types=1);

namespace App\Command;

use DateMalformedStringException;
use App\Service\DataWriter;
use DateTime;
use DateTimeZone;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use function in_array;

/**
 * Seeds deterministic sample Froggit weather readings into MariaDB and
 * InfluxDB, for `docker-compose.dev.yaml` — the fixed 2025-06-01/02 timestamps
 * make re-running this idempotent (DataWriter::write() upserts on MariaDB and
 * InfluxDB dedupes identical points), so it's safe to run every time the dev
 * stack comes up.
 *
 * Not wired into the production compose file at all (see docker-compose.yaml
 * vs. docker-compose.dev.yaml) — the `--force` guard below is only a second
 * line of defense against running it by hand against the Hub's shared,
 * production `data_froggit` table.
 */
#[AsCommand(
    name: 'app:fixtures:load',
    description: 'Seeds sample Froggit weather readings into MariaDB/InfluxDB for local development.',
)]
class LoadFixturesCommand extends Command
{
    private const string COLLECTION = 'froggit';

    public function __construct(
        private readonly DataWriter $dataWriter,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Allow running outside the dev/test environment.');
    }

    /**
     * @throws DateMalformedStringException
     * @throws DbalException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!in_array($this->environment, ['dev', 'test'], true) && !$input->getOption('force')) {
            $io->error(sprintf(
                'Refusing to load fixtures in the "%s" environment — production shares the Hub\'s real database. Pass --force to override.',
                $this->environment
            ));

            return Command::FAILURE;
        }

        $this->ensureTableExists($io);

        $count = 0;
        foreach (self::readings() as [$timestamp, $attribute, $value]) {
            $this->dataWriter->write(self::COLLECTION, $timestamp, null, $attribute, $value);
            $count++;
        }

        try {
            $this->dataWriter->flush();
        } catch (Throwable $throwable) {
            $io->warning('MariaDB fixtures were written, but flushing to InfluxDB failed: ' . $throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Loaded %d sample readings into the configured sinks.', $count));

        return Command::SUCCESS;
    }

    /**
     * The dev stack's MariaDB may still be finishing its own startup when this
     * command runs as part of `docker compose up`, so a handful of connection
     * attempts are retried before giving up.
     * @throws DbalException
     */
    private function ensureTableExists(SymfonyStyle $io): void
    {
        $attempts = 0;
        while (true) {
            try {
                if (!$this->dataWriter->tableExists(self::COLLECTION)) {
                    $this->dataWriter->make(self::COLLECTION, null);
                    $io->comment('Created the data_' . self::COLLECTION . ' table.');
                }

                return;
            } catch (DbalException $exception) {
                $attempts++;
                if ($attempts >= 10) {
                    throw $exception;
                }

                $io->comment('MariaDB not ready yet, retrying...');
                sleep(2);
            }
        }
    }

    /**
     * A deterministic two-day, hourly series across a handful of sensors —
     * fixed timestamps rather than "now" so repeated runs upsert the same
     * rows instead of growing the table.
     *
     * @return iterable<array{0: DateTime, 1: string, 2: float}>
     * @throws DateMalformedStringException
     */
    private static function readings(): iterable
    {
        $start = new DateTime('2025-06-01 00:00:00', new DateTimeZone('UTC'));

        for ($hour = 0; $hour < 48; $hour++) {
            $timestamp = (clone $start)->modify(sprintf('+%d hours', $hour));
            $angle = $hour * M_PI / 12;

            yield [$timestamp, 'temperature-out', round(12 + 8 * sin($angle), 1)];
            yield [$timestamp, 'temperature-living', round(21 + 1.5 * sin($angle), 1)];
            yield [$timestamp, 'humidity-out', round(70 - 20 * sin($angle))];
            yield [$timestamp, 'humidity-living', round(45 + 5 * sin($angle))];
            yield [$timestamp, 'barometer', round(1013 + 4 * sin($angle / 2), 1)];
            yield [$timestamp, 'wind-speed-avg10m', round(abs(3 * sin($angle / 3)), 1)];
            yield [$timestamp, 'wind-gust', round(abs(5 * sin($angle / 3)), 1)];
            yield [$timestamp, 'rain-hourly', $hour % 12 === 0 ? 1.0 : 0.0];
            yield [$timestamp, 'uv', max(0.0, round(5 * sin($angle - M_PI / 2)))];
            yield [$timestamp, 'solar-radiation', max(0.0, round(400 * sin($angle - M_PI / 2)))];
        }
    }
}
