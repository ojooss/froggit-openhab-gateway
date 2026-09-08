# AGENTS.md

This file provides guidance to AI Agents when working with code in this repository.

## Overview

Froggit-openHAB-Gateway: a small, standalone Symfony 8.0 (PHP 8.4) application. It receives HTTP
callbacks from a Froggit weather station and fans the readings out to three independent sinks:

1. MariaDB
2. InfluxDB
3. openHAB

Each sink is attempted and logged independently, but the result is **all-or-nothing**: the endpoint
returns 200 "ok" only if every *configured* sink succeeded; if even one of them fails, the whole
response is 500, listing which sink(s) failed (see `FroggitController::index()`).

Each sink is also **individually optional**, controlled purely by whether its URL env var is a
non-empty string — no separate feature flag:
- `MYSQL_URL` empty → `DataWriter` skips the MariaDB write (checked in `DataWriter`, since
  the Doctrine `Connection` service itself is always constructible even with a bad/empty URL — it
  only fails lazily on first actual query, which we simply never issue).
- `INFLUXDB_URL` empty → `InfluxService` never constructs the InfluxDB `Client` and every method
  (`write`/`flush`/`read`) becomes a no-op.
- `OPENHAB_URL` empty → `OpenHabPublisher::publish()` returns immediately (logged at debug level),
  without validating the workload or attempting any HTTP call.
A disabled sink is never counted as a "failed" sink — it's simply skipped, not attempted. This means
all sinks disabled at once is not an error either (200 "ok", even though nothing was written anywhere).

**Influx write timing:** `InfluxService::write()` only buffers points in memory; nothing is sent over
the network until `flush()` runs. There are two flush call sites with different failure behavior:
`FroggitService::flush()` → `DataWriter::flush()` is called explicitly by the controller, synchronously,
*before* the response is built, with `throwOnFailure: true` — this is how the "influx" sink's
pass/fail state is known in time to affect the response. The `register_shutdown_function` inside
`InfluxService`'s constructor is only a safety net for buffered points that weren't flushed for some
other reason; it always uses the default `throwOnFailure: false` so a failure there can never crash
the process after the response may already be underway.

## Common Commands

All PHP/composer commands run **inside the Docker container** — never install PHP, Composer or
MariaDB on the host. The Docker environment lives in `docker/` (Dockerfile, apache.conf, php.ini,
docker-compose.yaml, docker-compose.dev.yaml) — same layout as this user's other PHP projects
(todo-reminder, lego-manuals, cooking, ...), minus a custom entrypoint script: this app has no
cron/supervisor process to start, so it just inherits `php:8.4-apache`'s own
`docker-php-entrypoint` + `apache2-foreground`. Run `docker compose` from inside
`docker/`; relative paths in the compose files (`../` for the bind mount, `../.env.local`) are
resolved relative to the compose file's own directory.

```bash
cd docker

# Production-shaped: joins the existing home-automation-hub_default network, no local DB
docker compose up -d --build
docker compose exec froggit-gateway composer install

# Local dev/test: adds a throwaway local MariaDB + InfluxDB (docker-compose.dev.yaml),
# does NOT touch the Hub's network/DB — use this for iterating and running tests
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up -d --build
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway composer install

# Sample data for the local stack (see LoadFixturesCommand) — the `fixtures` one-off
# service already ran once as part of `up` above, but needed vendor/ to exist first,
# so on a fresh checkout re-run it now that composer install has finished:
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml up -d fixtures

# Run tests
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway composer test
# or a single test file
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway vendor/bin/phpunit tests/Service/FroggitServiceTest.php

# Static analysis
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway composer phpstan

# Automated refactoring (rector.php targets src/ and tests/)
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway composer rector:dry
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway composer rector

# Symfony console
docker compose -f docker-compose.yaml -f docker-compose.dev.yaml exec froggit-gateway php bin/console <command>
```

App code is bind-mounted into the container (`../:/var/www/html`, same pattern as the Hub and this
user's other projects) rather than baked into the image — `vendor/` therefore lives on the host too;
run `composer install` after cloning or after any dependency change. `var/` is an anonymous volume
(container-only) so cache/logs generated inside the container don't leak onto the host bind mount.

## Architecture

- `src/Controller/FroggitController.php` — HTTP endpoint (`/froggit`, `/data/report/`).
- `src/Service/FroggitService.php` — maps/converts raw Froggit sensor keys into `{ sensor => value }`.
- `src/Service/DataWriter.php` + `InfluxService.php` — based on the Hub's originals (dual-write to
  MariaDB via `<MYSQL_TABLE>_<collection>` tables, prefix `data` by default, and InfluxDB), but
  **diverge intentionally**: both gained the optional-sink behavior above, which the Hub's copies
  don't need (the Hub always has both databases available). Porting future Hub changes to these
  files needs manual merging, not a straight copy (no shared package). `DataWriter::write()`
  assumes the target `<MYSQL_TABLE>_<collection>` table already exists — it no longer creates it
  lazily on a missing-table error; see `SetupController` below.
- `src/Controller/SetupController.php` (`/setup`) — creates any `<MYSQL_TABLE>_<collection>` table listed in
  its `COLLECTIONS` constant that doesn't exist yet (via `DataWriter::make()`), and for tables that
  already exist, compares their actual columns (`DataWriter::actualColumns()`) against what `make()`
  would create today (`DataWriter::expectedColumns()`) and reports any drift. It does not migrate
  drifted tables itself — that's intentionally left as a manual/future step once real drift shows up.
  Add new collections to `COLLECTIONS` whenever a new caller starts writing through `DataWriter`.
- `src/Service/OpenHabPublisher.php` — new; maps sensor keys to openHAB item names
  (`config/services/openhab.yaml`) and PUTs values to the openHAB REST API.
- `config/services/froggit.yaml` — Froggit raw-key → sensor-name mapping + unit conversions.
- `config/services/openhab.yaml` — sensor-name → openHAB item-name mapping. **Contains placeholder
  item names** until the real openHAB item names are confirmed.
- `src/Service/DataReader.php` — read-side counterpart to `DataWriter`: reads
  `<MYSQL_TABLE>_<collection>` rows for a time range, in the same return shape as
  `InfluxService::read()`, so both sinks can be displayed the same way. No-ops (like the other
  sinks) when `MYSQL_URL` is empty; adapts to collections with or without a `device` column.
- `src/Controller/DataViewController.php` (`/data/view`) — lets a user pick a time range and one
  *configured* sink (MariaDB via `DataReader`, or InfluxDB via `InfluxService::read()`) and shows
  the matching rows as an HTML table. Uses a real Symfony form (`src/Form/DataViewSearchType.php`
  + `src/Form/Model/DataViewSearch.php`) rather than manual request parsing — this is why
  `symfony/form` and `symfony/twig-bundle` were added as dependencies (the app previously had no
  Twig integration at all; `StatusController` wires up its own standalone `Twig\Environment`
  instead, which predates this and still does its own thing). `symfony/validator` is intentionally
  **not** installed, so form validation is limited to what the Form component does on its own
  (invalid/unconfigured choice, unparsable datetime) plus a `POST_SUBMIT` listener in the form type
  for the one cross-field rule (`from` must not be after `to`); an invalid submission renders with
  HTTP 422 automatically.
- `src/Command/LoadFixturesCommand.php` (`app:fixtures:load`) — seeds a deterministic two-day,
  hourly series of sample Froggit readings into MariaDB and InfluxDB via `DataWriter`, so
  `/data/view` has something to show in the local dev stack. Fixed 2025-06-01/02 timestamps make
  repeated runs idempotent (MariaDB upserts on the existing primary key, InfluxDB dedupes identical
  points), which is what makes it safe to run automatically as part of `docker-compose.dev.yaml`'s
  `fixtures` service on every `up`. Refuses to run outside `dev`/`test` (`--force` overrides) as a
  second line of defense — the real safeguard is that `fixtures` only exists in
  `docker-compose.dev.yaml`, never in the production `docker-compose.yaml`, since production shares
  the Hub's real `data_froggit` table (see "Relationship to home-automation-hub" below).

## Configuration / Secrets

`.env.local` (gitignored, repo root) holds the real MariaDB/InfluxDB/openHAB credentials for
**production** use, copied from the Hub's own `.env.local`. It's passed into the container via
`env_file: path: ../.env.local, required: false` in `docker/docker-compose.yaml` rather than
Compose's own `${VAR}` substitution — `required: false` because `docker-compose.dev.yaml` sets
every value it would otherwise provide directly via `environment:`, so a fresh dev checkout
doesn't need this file to exist at all.

## Testing

PHPUnit 12 with Symfony's test bridge, configured to fail on deprecations/notices/warnings
(`phpunit.xml.dist`). `FroggitServiceTest` and `FroggitControllerTest` need a reachable test MariaDB
(via `MYSQL_URL`); `OpenHabPublisherTest` uses `Symfony\Component\HttpClient\MockHttpClient`
and needs no network access. `docker-compose.dev.yaml` deliberately leaves `OPENHAB_URL` empty (no
real openHAB in that stack) so the sink is disabled during `composer test` rather than always
failing; `FroggitControllerTest::testOneFailingSinkReturns500` swaps in a mocked `OpenHabPublisher`
via `static::getContainer()->set(...)` to exercise the "one configured sink fails" path instead.

`nyholm/psr7` is a required dependency purely to satisfy `php-http/discovery` for
`influxdata/influxdb-client-php` (it needs a PSR-17 factory implementation to construct its write/query
API clients) — without it, every InfluxDB write silently failed with a `DiscoveryFailedException` that
only surfaced once `InfluxService::flush(throwOnFailure: true)` stopped swallowing it.

### CI

`.github/workflows/tests.yml` runs on every push/PR and mirrors `docker-compose.dev.yaml`'s throwaway
dev stack rather than disabling the sinks: it spins up `mariadb`/`influxdb` service containers with the
same credentials (`froggit`/`froggit`/`froggit` db, `dev-token`/`froggit` org+bucket), waits for InfluxDB
to answer `/health`, then runs `php bin/console app:fixtures:load` to create `data_froggit` (via
`LoadFixturesCommand::ensureTableExists()`, since `DataWriter` no longer creates tables lazily — see
above) and seed it, before `vendor/bin/phpunit`. `OPENHAB_URL` stays empty, same as the dev stack, so
that sink is disabled in CI too. This is required, not just nice-to-have: `FroggitServiceTest::testPersist`
and the `DataViewControllerTest` sink tests need a real, reachable MariaDB/InfluxDB and a pre-existing
table — an earlier version of the workflow blanked `MYSQL_URL`/`INFLUXDB_URL` to avoid needing external
services, which broke those tests instead.

### Releases

Cutting a release is just pushing an annotated version tag on `master` — everything else is automatic:

```bash
git tag -a v1.2.0 -m "v1.2.0"
git push origin v1.2.0
```

That tag push triggers the same `tests.yml` workflow (its `on.push.tags` covers `v*`), which runs
`phpunit`/`phpstan`/`rector`/`composer-audit` again against the tagged commit itself — not just trusting
that some earlier PR check covered it — and only then runs the `docker-publish` job (`needs: [phpunit,
phpstan, rector, composer-audit]`, gated by `if: startsWith(github.ref, 'refs/tags/v')` so it never runs
on a plain `master` push). That job builds `docker/Dockerfile`'s `prod` target — unlike the `dev` target
used by both `docker-compose.yaml`/`docker-compose.dev.yaml`, which relies on the app code being
bind-mounted in, `prod` bakes the app and its `--no-dev` Composer dependencies into the image so it runs
standalone — and pushes it to `ghcr.io/ojooss/froggit-openhab-gateway` tagged `X.Y.Z`, `X.Y`, `latest`,
and `sha-<short-sha>`, then creates a GitHub Release for the tag with `gh release create --generate-notes`
(auto-generated notes from PRs merged since the last tag).
