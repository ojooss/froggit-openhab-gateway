# Froggit openHAB Gateway

## 1) Purpose of the Application

This application is a small, standalone Symfony backend that receives readings from a Froggit
weather station and forwards them to up to three destinations:

- **MariaDB** – storing the raw values in the `data_froggit` table (prefix configurable via `MYSQL_TABLE`)
- **InfluxDB** – writing to the `froggit` measurement (e.g. for Grafana dashboards)
- **openHAB** – updating items via the openHAB REST API (`PUT /rest/items/{item}/state`)

Each of these three destinations ("sinks") is contacted and logged independently. The response
to the weather station's callback is **all-or-nothing**: only if *every configured* sink
succeeds does the application respond with `200 ok`; if even one fails, `500` is returned along
with a list of the failed sinks.

Each sink is also **individually optional** – controlled purely by its corresponding URL
environment variable (`MYSQL_URL`, `INFLUXDB_URL`, `OPENHAB_URL`). If one of these is empty, the
corresponding sink is silently skipped instead of being treated as a failure – to the point that
if all three sinks are disabled, the application still returns `200 ok`, even though nothing is
written anywhere.

Raw values in imperial units (mph, °F, inHg, in, W/m²), as reported by Froggit stations, are
converted to metric (m/s, °C, hPa, mm, lux) by default before being passed to the sinks
(controllable via `FROGGIT_CONVERT_ENABLED`).

## 2) Setup, Configuration, and Runtime

The application runs exclusively in Docker (PHP 8.4 + Apache) – PHP, Composer, or MariaDB are
never installed locally on the host. The application code is bind-mounted into the container,
so `vendor/` also lives on the host.

**Production** (joins the existing Docker network and the MariaDB/InfluxDB running there;
credentials come from the gitignored `.env.local`):

```bash
cd docker
docker compose up -d --build
docker compose exec froggit-gateway composer install
```

Configuration is handled via environment variables (see `.env` for defaults, `.env.local` for
real credentials):

| Variable                                                            | Purpose                                                       |
|---------------------------------------------------------------------|---------------------------------------------------------------|
| `MYSQL_URL`                                                         | MariaDB connection; empty = sink disabled                     |
| `MYSQL_TABLE`                                                       | MariaDB table name prefix (default: `data`); actual table per collection is `<MYSQL_TABLE>_<collection>` |
| `INFLUXDB_URL`, `INFLUXDB_TOKEN`, `INFLUXDB_ORG`, `INFLUXDB_BUCKET` | InfluxDB connection; `INFLUXDB_URL` empty = sink disabled     |
| `OPENHAB_URL`, `OPENHAB_TOKEN`                                      | openHAB REST API; `OPENHAB_URL` empty = sink disabled         |
| `FROGGIT_CONVERT_ENABLED`                                           | `1`/`0` – convert imperial units to metric (default: enabled) |

Sensor and item mappings live in `config/services/froggit.yaml` (Froggit raw key → sensor name,
including unit conversion) and `config/services/openhab.yaml` (sensor name → openHAB item name).

## 3) Usage / API Endpoints

| Route                                    | Purpose                                                                                                                                                                                                                                                                                                                 |
|------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `GET`/`POST` `/froggit`, `/data/report/` | Callback endpoint for the weather station. Accepts the raw values as query or POST parameters, converts them, and writes them to every configured sink. Response: `200 {"message": "ok"}` on full success, `500 {"message": "sink(s) failed: ..."}` if at least one configured sink fails, `400` on missing parameters. |
| `GET` `/setup`                           | Creates any missing `<MYSQL_TABLE>_<collection>` tables in MariaDB and, for existing tables, reports any column drift (missing/extra/mismatched type) against the expected schema. Does not automatically migrate drifted tables.                                                                                                |
| `GET` `/status`                          | Status page (HTML) that runs a health check for each configured sink (DB connection, InfluxDB `/health`, openHAB `/rest`) and returns `503` as soon as one check fails.                                                                                                                                                 |
| `GET` `/data/view`                       | HTML form for browsing stored readings: pick a time range, one configured sink (MariaDB or InfluxDB), and a collection, and view the matching rows as a table.                                                                                                                                                          |

The weather station must be configured to send its custom-server upload requests to
`/data/report/` on this application (Froggit stations use this path format by default for
"Ecowitt-compatible" custom-server uploads).

## 4) Architecture & Data Flow

```
Froggit weather station
        │  HTTP GET/POST (raw values, imperial units)
        ▼
FroggitController (/froggit, /data/report/)
        │
        ▼
FroggitService  ── maps raw keys → sensor names, converts units
        │
        ├──▶ DataWriter        ──▶ MariaDB (<MYSQL_TABLE>_froggit, default data_froggit)
        ├──▶ InfluxService     ──▶ InfluxDB (measurement "froggit")
        └──▶ OpenHabPublisher  ──▶ openHAB REST API (PUT /rest/items/{item}/state)
```

Each sink is handled with its own try/catch block and logged individually in
`FroggitController::index()`; a failure in one sink does not block the others. Only at the end
is it decided whether the overall response is `200` or `500`.

Important for InfluxDB writes: `InfluxService::write()` only buffers data points in memory –
only `flush()` actually sends them over the network. The controller calls `flush()`
synchronously with `throwOnFailure: true`, so that an Influx failure can still be reflected in
the response's sink failure list in time. An additional `register_shutdown_function` hook in
`InfluxService` serves only as a safety net for points that weren't flushed for some other
reason, and never throws an exception there.

The MariaDB and InfluxDB instances used here may also be shared with other consumers
(e.g. scheduled jobs, Grafana dashboards) that read `data_froggit` directly – so `MYSQL_TABLE`
must stay at its default (`data`) unless those consumers are updated too.
