# ⏱️ Benchmark — HTTP Server CLI

Benchmarks for **Bootgly HTTP Server CLI**, split into two **load sets**:

- **`techempower`** — the seven canonical TechEmpower routes (`/plaintext`, `/json`, `/db`, `/query`, `/fortunes`, `/updates`, `/cached-queries`), served identically across frameworks for a **fair cross-framework comparison**. Every cross-framework opponent now serves the TechEmpower routes via **Docker** (each opponent auto-builds its image and runs containerized); **Bootgly** is the native, pure-PHP baseline (no Docker, no system deps).
- **`benchmark`** — a Bootgly-internal stress surface (100 static, 100 dynamic, nested groups, per-route middleware, catch-all, plus DB probes) for **self-comparison during framework development**. Bootgly-only.

Select the set in the **required** `--loads=<set>:<indexes>` option — `<set>` is `techempower` or `benchmark`, `<indexes>` is `*` (all) or a comma list (e.g. `techempower:1,2`). The Bootgly opponent derives the matching server router from the set automatically; set `BOOTGLY_HTTP_SERVER_CLI_ROUTER=<set>` only to override it.

---

## 📋 Table of Contents

- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Loads](#-loads)
- [Runners](#-runners)
- [Opponents](#-opponents)
- [Configuration](#-configuration)
- [Running Benchmarks](#-running-benchmarks)
- [Probes](#-probes)
- [Environment Notes](#-environment-notes)
- [Results](#-results)

---

## ✅ Prerequisites

| Dependency | Purpose | Required |
|-----------|---------|----------|
| **PHP** ≥ 8.4 | Server runtime | ✅ |
| **Composer** | PHP dependency management | ✅ |
| **lsof** | Port detection | ✅ |
| **curl** | Server readiness check | ✅ |
| **nproc** | CPU count detection | ✅ |
| **PostgreSQL** | DB loads (`/db`, `/query`, `/fortunes`, `/updates`, `/cached-queries`) + Probes | Only for DB loads |
| **psql** | Seeds the TechEmpower tables (`World`, `Fortune`, `CachedWorld`) + Probe control query | Only for DB loads |
| **Docker** | Runs every cross-framework opponent (each opponent auto-builds its image on first start). Bootgly itself needs no Docker. | Only for cross-framework |
| **Swoole extension** + `pdo_pgsql` | Inside the Docker image (no host install) | — |
| **FrankenPHP binary** | Inside the Docker image (no host install) | — |

> **No sudo / no system PostgreSQL?** Start a throwaway local instance — **no `sudo` needed** —
> with the bundled script:
>
> ```bash
> bash artifacts/@postgresql/postgresql-demo.sh start    # also: stop | restart | status
> ```
>
> It `initdb`s (auth=`trust`, no password) and starts PostgreSQL on `127.0.0.1:5432`, creating the
> `bootgly` database as role `postgres`. PGDATA lives in `bootgly/storage/temp/postgresql-demo`.
>
> Then run the TechEmpower DB set with `--loads=techempower:*` (the techempower set seeds
> the tables; the Bootgly opponent boots the matching `techempower` server SAPI automatically):
>
> ```bash
> DB_HOST=127.0.0.1 DB_PORT=5432 DB_USER=postgres DB_NAME=bootgly DB_PASS= \
>   ./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=techempower:*
> ```

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

### 1. Opponent dependencies (Docker)

> Every cross-framework opponent now runs in **Docker** — there are no per-opponent
> native install steps (no `composer install`, no `pecl install swoole`, no FrankenPHP
> `curl`). Each opponent's start script builds its image if missing
> (`docker image inspect <image> || docker build -f Dockerfile.<name> -t <image> <context>`)
> and then runs it with `--network host` (the container binds `:8082` directly and
> reaches host PostgreSQL at `127.0.0.1:5432` — no NAT, fair). The container runs the
> server in the foreground (the container *is* the daemon). Only a **running Docker
> daemon** is required on the host; all language runtimes and extensions live inside
> the images.

**Bootgly is the only native (non-Docker) opponent** — pure PHP, no system deps.

To avoid paying the build cost during a timed sweep, pre-build the images first
(otherwise they build automatically on the opponent's first `start`):

```bash
cd bootgly_benchmarks
docker build -f Dockerfile.<name> -t bootgly-<name> HTTP_Server_CLI/bootables/<context>
```

Each `Dockerfile.<name>` lives at the `bootgly_benchmarks` repo root; the build context
is the opponent's bootable directory:

| Opponent(s) | Docker image | Build context |
|-------------|--------------|---------------|
| `swoole-base`, `swoole-process`, `swoole-coroutine`, `swoole-techempower` | `bootgly-swoole` (shared) | `HTTP_Server_CLI/bootables/swoole` |
| `hyperf` | `bootgly-hyperf` | `HTTP_Server_CLI/bootables/hyperf` |
| `workerman` | `bootgly-workerman` | `HTTP_Server_CLI/bootables/workerman` |
| `roadrunner` | `bootgly-roadrunner` | `HTTP_Server_CLI/bootables/roadrunner` |
| `frankenphp` | `bootgly-frankenphp` | `HTTP_Server_CLI/bootables/frankenphp` |
| `laravel-nginx` | `bootgly-laravel-nginx` | `HTTP_Server_CLI/bootables/laravel` |
| `laravel-apache` | `bootgly-laravel-apache` | `HTTP_Server_CLI/bootables/laravel` |
| `laravel-octane` | `bootgly-laravel-octane` | `HTTP_Server_CLI/bootables/laravel` |
| `laravel-ols` | `bootgly-laravel-ols` | `HTTP_Server_CLI/bootables/laravel` |

Worker count is read from `BOOTGLY_WORKERS` (default `nproc / 2`). DB connection is
passed via `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` (the Laravel images
remap these to `DB_CONNECTION=pgsql` + `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`).
Stopping an opponent removes its container (`docker rm -f <container>`).

> **Note:** no benchmark sweep has been run for the cross-framework opponents yet — the
> images are built and **functionally smoke-tested only** (no throughput numbers below
> are derived from them).

---

## 🎯 Loads

The case ships **two load sets**, selected with the required `--loads=<set>:<indexes>`
option. The Bootgly opponent derives the matching server SAPI
(`BOOTGLY_HTTP_SERVER_CLI_ROUTER`) from `<set>` automatically. A *load* is one PHP file
under `loads/<set>/` describing a request pattern (method, paths, expected response)
for the TCP_Client generator.

### TechEmpower set (`loads/techempower/`)

Seven canonical TechEmpower routes, identical across opponents. Backed by
`projects/Benchmark/HTTP_Server_CLI/router/techempower-benchmark.SAPI.php`.

| # | File | Route | Description |
|---|------|-------|-------------|
| 1 | `1.0.1-plaintext` | `GET /plaintext` | `text/plain "Hello, World!"` |
| 2 | `1.0.2-json` | `GET /json` | `application/json {"message":"Hello, World!"}` |
| 3 | `1.0.3-db` | `GET /db` | Single random `World` row as JSON |
| 4 | `1.0.4-query` | `GET /query?queries=20` | Pipeline 20 random `World` fetches |
| 5 | `1.0.5-fortunes` | `GET /fortunes` | Fortune list rendered as HTML |
| 6 | `1.0.6-updates` | `GET /updates?queries=20` | 20 random `World` reads + batched UPDATE |
| 7 | `1.0.7-cached-queries` | `GET /cached-queries?count=20` | 20 random `CachedWorld` rows from an in-memory cache |

All loads tagged `@opponents: all` — cross-framework fair comparison.

### Bootgly set (`loads/benchmark/`)

Bootgly-internal stress surface, for self-comparison during framework
development. Backed by
`projects/Benchmark/HTTP_Server_CLI/router/bootgly-benchmark.SAPI.php`. Every
load is tagged `@opponents: Bootgly`, so the runner skips cross-framework
opponents automatically — even if you pass `--opponents=bootgly,swoole-base`.

| # | File | Group | Route(s) |
|---|------|-------|----------|
| 1 | `1.1.1-static_single` | Static | `/` on every request |
| 2 | `1.1.2-static_10` | Static | 10 named static routes |
| 3 | `1.1.3-static_100` | Static | 100 static routes |
| 4 | `1.2.1-dynamic_1` | Dynamic | `/user/:id` |
| 5 | `1.2.2-dynamic_10` | Dynamic | 10 dynamic routes, varying params |
| 6 | `1.2.3-dynamic_100` | Dynamic | 100 dynamic routes, varying params |
| 7 | `1.3.1-catch_all` | Catch-all | non-existent paths → `404` |
| 8 | `2.1.1-nested_6` | Nested | `/admin/*` + `/account/*` groups |
| 9 | `3.1.1-middleware_3` | Middleware | `/protected/*` (real `RequestId` middleware) |
| 10 | `z.1.1-mixed_8` | Mixed | 5 static + 3 dynamic |
| 11 | `z.1.2-mixed_20` | Mixed | 10 static + 10 dynamic |
| 12 | `z.1.3-full_mix` | Mixed | static + dynamic + nested + middleware + 404 |
| 13 | `4.1.1-database_native_ping` | DB probe | `/database/native/ping` |
| 14 | `4.1.2-database_runner_ping` | DB probe | `/database/resource/ping` |
| 15 | `4.2.1-database_native_parameters` | DB probe | `/database/native/parameters` |
| 16 | `4.2.2-database_runner_parameters` | DB probe | `/database/resource/parameters` |
| 17 | `4.3.1-database_native_pool` | DB probe | `/database/native/pool` |
| 18 | `4.3.2-database_runner_pool` | DB probe | `/database/resource/pool` |
| 19 | `4.4.1-database_native_sleep` | DB probe | `/database/native/sleep` |
| 20 | `4.4.2-database_runner_sleep` | DB probe | `/database/resource/sleep` |

### `@opponents` tag

Each load file has a `@opponents:` header controlling which opponents run it:

- `all` — every registered opponent runs the load.
- `Bootgly` — only Bootgly runs it; other opponents are skipped even when
  passed via `--opponents=`.

This keeps the cross-framework `techempower` set fair while the `benchmark` set
stays Bootgly-only.

### Database Benchmark

The cross-framework data comparison is the `techempower` set's four DB routes plus
the cached-queries route (loads `3,4,5,6,7`). They are neutral across opponents and
run against Bootgly and **Swoole TechEmpower**:

| Route | Purpose |
|-------|---------|
| `/db` | Fetch one random `World` row |
| `/query?queries=20` | Fetch 20 random `World` rows |
| `/fortunes` | Fetch, append, sort, escape, and render `Fortune` rows |
| `/updates?queries=20` | Fetch and update 20 random `World` rows |
| `/cached-queries?count=20` | Fetch 20 random `CachedWorld` rows from an in-memory cache (primed from DB) |

The Bootgly `benchmark` set additionally ships `SELECT 1`, parameterized,
multi-query, and `pg_sleep` probes (loads `13–20`). They isolate DBAL/resource
overhead but are Bootgly-only (`@opponents: Bootgly`), not a cross-framework
comparison:

| Native route | Resource route | Purpose |
|--------------|----------------|---------|
| `/database/native/ping` | `/database/resource/ping` | `SELECT 1` helper overhead |
| `/database/native/parameters` | `/database/resource/parameters` | parameterized query overhead |
| `/database/native/pool` | `/database/resource/pool` | pool/concurrent operations overhead |
| `/database/native/sleep` | `/database/resource/sleep` | slow async query overhead |

#### Running

Run from the `bootgly` repository — Bootgly only:

```bash
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_NAME=bootgly \
DB_USER=postgres \
DB_PASS= \
DB_SSLMODE=disable \
DB_SSLVERIFY=false \
DB_POOL_MAX=1 \
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --runner=tcp_client --connections=1024 --duration=10 --server-workers=24 --client-workers=4 --loads=techempower:3,4,5,6
```

Compare Bootgly with the Swoole TechEmpower opponent:

```bash
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_NAME=bootgly \
DB_USER=postgres \
DB_PASS= \
DB_SSLMODE=disable \
DB_SSLVERIFY=false \
DB_POOL_MAX=1 \
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,swoole-techempower --runner=tcp_client --connections=1024 --duration=10 --server-workers=24 --client-workers=4 --loads=techempower:3,4,5,6
```

The Swoole TechEmpower opponent requires PHP extensions `swoole` and `pdo_pgsql`
(`php8.4-pgsql` on this machine). It follows the TechEmpower Swoole PostgreSQL
pattern with `Swoole\Database\PDOPool` and does not use Bootgly runtime code.

#### Tuning notes

From WSL2 / Ryzen 9 3900X / PostgreSQL `max_connections=100`:

- `DB_POOL_MAX=1` per HTTP worker was fastest for short microbenchmarks.
- On a single machine running client, server, and PostgreSQL together,
  `--server-workers=24` + `--client-workers=4` is the best balanced full-suite
  default observed so far.
- `--server-workers=28` + `--client-workers=5` favored peak ping/parameter
  throughput, while 24/4 left more CPU headroom for pool-heavy loads.
- For Swoole comparison, `DB_POOL_MAX=1` is the strict single-connection-per-worker
  control. `DB_POOL_MAX=3` is the recommended comparison setting; `DB_POOL_MAX=4`
  uses 96 DB connections at 24 workers and leaves little PostgreSQL headroom while
  producing similar results.
- Older `126k..130k req/s` artifacts did not validate HTTP status/body; the current
  TCP_Client preflight rejects 404/500 before timed runs.
- `--connections=512` is a lower-latency alternative with similar throughput.
- The `pg_sleep` loads are async-behavior checks, not TechEmpower loads.

---

## 🔧 Runners

The case uses Bootgly's built-in **TCP_Client** load generator
(`--runner=tcp_client`, the default — no external tooling required). It loads the
PHP loads of the active set (`loads/techempower/` or `loads/benchmark/`) and
supports multi-worker forking and HTTP pipelining.

| Option | Default | Description |
|--------|---------|-------------|
| `--client-workers=N` | auto | Number of client worker processes |
| `--connections=N` | `514` | Number of TCP connections |
| `--duration=N` | `10` | Benchmark duration in seconds |
| `--pipeline=N` | `1` | HTTP pipelining factor |

---

## 🏁 Opponents

| Server | Runtime | TFB routes | Docker image |
|--------|---------|-----------|--------------|
| **Bootgly** HTTP Server CLI | PHP (event-loop) | 7 | native baseline (no Docker) |
| **Swoole** (Base mode) | PHP (C extension), reactor per worker | 7 (TechEmpower bootable in `SWOOLE_BASE` mode) | `bootgly-swoole` (shared) |
| **Swoole** (Process mode) | PHP (C extension), master + workers | generic route set only (mode demo) | `bootgly-swoole` (shared) |
| **Swoole** (Coroutine mode) | PHP (C extension), coroutine + fork | generic route set only (mode demo) | `bootgly-swoole` (shared) |
| **Swoole** TechEmpower | PHP (C extension), `SWOOLE_BASE` + PDO pool | 7 | `bootgly-swoole` (shared) |
| **Hyperf** | PHP (Swoole framework), full-stack coroutine | 7 (`/cached-queries` = per-worker in-memory `CachedWorld` cache) | `bootgly-hyperf` |
| **Workerman** v5 | PHP (event-loop), sync per-worker PDO | 7 | `bootgly-workerman` |
| **RoadRunner** | Go + PHP (goridge), PSR-7 worker, per-worker PDO | 7 | `bootgly-roadrunner` |
| **FrankenPHP** | Go + PHP worker mode (Caddy-based), per-worker PDO | 7 | `bootgly-frankenphp` |
| **ReactPHP** | PHP (pure-async event loop), fork N + `SO_REUSEPORT`, async PG via `voryx/pgasync` | 7 | `bootgly-reactphp` |
| **AMPHP** | PHP (Amp v3 fibers, pure-async), fork N + `SO_REUSEPORT`, async PG via `amphp/postgres` | 7 | `bootgly-amphp` |
| **Laravel** (nginx) | PHP-FPM 8.4 behind nginx, per-request | 6 (no `/cached-queries`) | `bootgly-laravel-nginx` |
| **Laravel** (Apache) | PHP-FPM 8.4 behind Apache `mpm_event`, per-request | 6 (no `/cached-queries`) | `bootgly-laravel-apache` |
| **Laravel** (Octane) | Laravel Octane on Swoole, persistent workers | 6 (no `/cached-queries`) | `bootgly-laravel-octane` |
| **Laravel** (OLS) | OpenLiteSpeed + LSCache | 6 (LSCache serves `/plaintext`, `/json`, `/fortunes` from cache; `/db`, `/query`, `/updates` dynamic) | `bootgly-laravel-ols` |

Event-loop opponents use `nproc / 2` workers for fair CPU distribution. The
**Laravel** opponents are the exception: PHP-FPM is per-request (one child = one
blocking request), so it runs a fixed static process pool (`FPM_MAX_CHILDREN`,
default `64`) instead of `nproc / 2`. The pool size also caps concurrent Postgres
connections, so keep it below the server's `max_connections` (default `100`).

Every cross-framework opponent now serves the TechEmpower routes via Docker. The
persistent-worker opponents — `swoole-base`, `swoole-techempower`, `hyperf`,
`workerman`, `roadrunner`, `frankenphp`, `reactphp`, `amphp` — implement all **seven** TechEmpower routes
(including `/cached-queries`). The four **Laravel** stacks (`laravel-nginx`,
`laravel-apache`, `laravel-octane`, `laravel-ols`) serve **six** (see below). The
`swoole-process` and `swoole-coroutine` variants remain **generic-route mode demos** —
they serve the generic route set, not the TechEmpower routes, so they are not part of a
`techempower` sweep.

> Laravel implements only six TechEmpower routes — `/cached-queries` is deliberately
> **N/A** for Laravel. The four Laravel variants share one app, and PHP-FPM (nginx,
> Apache) is per-request: a fresh worker per request cannot hold an in-process cache, so
> a `/cached-queries` route there would be meaningless. The persistent-worker opponents
> (swoole, hyperf, workerman, roadrunner, frankenphp, reactphp, amphp) do implement all seven.

> The Laravel stacks run a full framework bootstrap **per request** (no persistent
> worker) — the mainstream popular deployment. They are expected to score far below
> the persistent-worker opponents; that contrast is the point of including them.

> No benchmark sweep has been run for the new opponents yet — their images are built and
> **functionally smoke-tested only**, not measured for throughput.

### Available names

CLI filter values for `--opponents=`:

| Name | Description |
|------|-------------|
| `bootgly` | Bootgly HTTP Server CLI — native (non-Docker) baseline, all 7 TechEmpower routes |
| `swoole-base` | Swoole `SWOOLE_BASE` mode (image `bootgly-swoole`) — swaps to the TechEmpower bootable, all 7 routes |
| `swoole-process` | Swoole `SWOOLE_PROCESS` mode (image `bootgly-swoole`) — generic route set only (mode demo, no TFB) |
| `swoole-coroutine` | Swoole Coroutine mode (image `bootgly-swoole`) — generic route set only (mode demo, no TFB) |
| `swoole-techempower` | Swoole `SWOOLE_BASE` + PDO pool (image `bootgly-swoole`) — all 7 TechEmpower routes |
| `hyperf` | Hyperf, Swoole framework (image `bootgly-hyperf`) — all 7 routes (`/cached-queries` = per-worker in-memory cache) |
| `workerman` | Workerman v5, sync per-worker PDO (image `bootgly-workerman`) — all 7 routes |
| `roadrunner` | RoadRunner (Go + PHP), PSR-7 worker, per-worker PDO (image `bootgly-roadrunner`) — all 7 routes |
| `frankenphp` | FrankenPHP worker mode, per-worker PDO (image `bootgly-frankenphp`, base `dunglas/frankenphp:php8.4`) — all 7 routes |
| `reactphp` | ReactPHP pure-async event loop, fork N + `SO_REUSEPORT`, async PG via `voryx/pgasync` (image `bootgly-reactphp`) — all 7 routes |
| `amphp` | AMPHP (Amp v3 fibers) pure-async, fork N + `SO_REUSEPORT`, async PG via `amphp/postgres` (image `bootgly-amphp`) — all 7 routes |
| `laravel-nginx` | Laravel on PHP-FPM 8.4 behind nginx, per-request (image `bootgly-laravel-nginx`) — 6 routes (no `/cached-queries`) |
| `laravel-apache` | Laravel on PHP-FPM 8.4 behind Apache `mpm_event`, per-request (image `bootgly-laravel-apache`) — 6 routes (no `/cached-queries`) |
| `laravel-octane` | Laravel Octane on Swoole, persistent workers (image `bootgly-laravel-octane`) — 6 routes (no `/cached-queries`) |
| `laravel-ols` | Laravel on OpenLiteSpeed + LSCache (image `bootgly-laravel-ols`) — 6 routes (LSCache serves `/plaintext`, `/json`, `/fortunes`; `/db`, `/query`, `/updates` dynamic) |

### Laravel opponents (`laravel-nginx`, `laravel-apache`, `laravel-octane`, `laravel-ols`)

All four variants serve the **same** app (`bootables/laravel/`), each in its own Docker
image (`bootgly-laravel-nginx` / `-apache` / `-octane` / `-ols`). `laravel-nginx` and
`laravel-apache` front **PHP-FPM 8.4** with nginx / Apache `mpm_event` (per-request);
`laravel-octane` runs **Laravel Octane on Swoole** with persistent workers; `laravel-ols`
runs **OpenLiteSpeed + LSCache**. The image bakes everything in (`composer install --no-dev`,
the web server, `pdo_pgsql`, and the FPM/web-server configs rendered from `configs/*.tpl`) —
there is no native `apt-get`/`composer install`/`php artisan optimize` step on the host.

Prerequisites — only a **running Docker daemon** plus a **seeded host PostgreSQL**:

```bash
# Postgres seeded with the World/Fortune tables (reached at host 127.0.0.1:5432):
bash artifacts/@postgresql/postgresql-demo.sh start
```

The image auto-builds on the opponent's first `start`; pre-build it to keep the build cost
out of a timed sweep (from `bootgly_benchmarks`):

```bash
docker build -f Dockerfile.laravel-nginx  -t bootgly-laravel-nginx  HTTP_Server_CLI/bootables/laravel
docker build -f Dockerfile.laravel-apache -t bootgly-laravel-apache HTTP_Server_CLI/bootables/laravel
docker build -f Dockerfile.laravel-octane -t bootgly-laravel-octane HTTP_Server_CLI/bootables/laravel
docker build -f Dockerfile.laravel-ols    -t bootgly-laravel-ols    HTTP_Server_CLI/bootables/laravel
```

Tuning knobs (env, forwarded into the container via `docker run -e`): `BENCHMARK_PORT`
(default `8082`), `BOOTGLY_WORKERS` / `FPM_MAX_CHILDREN` (default `64`; the static FPM pool
size for the FPM variants, which also caps concurrent Postgres connections — keep below PG
`max_connections`), `FPM_JIT` (`tracing` | `function` | `off`; default `tracing`). The DB is
the Bootgly `DB_*` set (`DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS`), which the
opponent script remaps to Laravel's `DB_CONNECTION=pgsql` + `DB_HOST` / `DB_PORT` /
`DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`.

#### Performance notes (how these stacks are tuned)

The FPM pool + web-server tuning is **baked into the image** (the Dockerfile/entrypoint sets
`XDEBUG_MODE=off` and `APP_ENV=production`, and renders the FPM/web-server configs from
`configs/*.tpl`). The configs mirror TechEmpower's Laravel reference
(`opcache.validate_timestamps=0`, `save_comments=0`, `enable_file_override=1`,
`huge_code_pages=1`, `jit_buffer_size=128M`, `opcache.preload`, classmap-authoritative
autoloader). The web servers route every request straight to `public/index.php` over the FPM
socket (no `try_files`/`mod_rewrite` filesystem stat).

- **Xdebug must be off in FPM.** If `xdebug` is enabled in the FPM SAPI it overrides
  `zend_execute_ex`, force-disabling JIT and adding heavy per-opcode overhead, which
  cuts Laravel throughput several-fold. The image starts FPM with `XDEBUG_MODE=off`
  to prevent this; Xdebug is not installed in the image at all.
- **JIT is effectively noise for this stateless per-request workload** (tracing vs off
  stays within the run-to-run band). The real lever is Xdebug-off + opcache, not JIT.
- **nginx is the faster front; Apache trails somewhat.** Apache needs a warm thread pool
  (`StartServers`/`MinSpareThreads`); `mod_proxy_fcgi enablereuse=on` *hurt* (serialized
  the unix socket) and is left off.
- **The DB routes (`/db`,`/query`) plateau well below the static routes** — bottlenecked
  on the per-request Postgres *connect* (the FPM variants open a fresh connection each
  request), not on opcode execution. `laravel-octane` is the exception: persistent Swoole
  workers, not per-request FPM, so it does not pay that per-request connect cost.

Absolute throughput is environment-dependent — run the suite on your own hardware. JIT is
exposed as `FPM_JIT` precisely so you can confirm the tracing-vs-off result for your box.

### Adding an opponent

#### 1. Create the opponent script

Each opponent lives in its own folder under `opponents/` and may hold several
variations (e.g. `opponents/swoole/` has `swoole-base.php`,
`swoole-techempower.php`, …). Create `opponents/myserver/myserver.php` — it
starts the HTTP server and blocks until killed:

```php
<?php
// Start your server on $port with $workers processes.
// The runner calls this script as a subprocess and kills it when done.
```

See existing scripts under `opponents/*/` for the exact pattern (start server,
listen on `getenv('PORT')`, use `getenv('WORKERS')` for worker count).

#### 2. Create the server artifact

Create `bootables/myserver/` serving the seven TechEmpower routes so the opponent
joins the cross-framework `techempower` set:

- `/plaintext`, `/json`, `/db`, `/query?queries=N`, `/fortunes`, `/updates?queries=N`, `/cached-queries?count=N`
- See `bootables/swoole/swoole-techempower-postgres.php` for the reference implementation.

#### 3. Self-register via `opponents/myserver/@.php`

Each opponent folder ships an `@.php` that registers itself — the case's main
`@.php` auto-discovers them with `glob(opponents/*/@.php)`, so you never edit it.
A folder may register several variations (see `opponents/swoole/@.php`):

```php
<?php

use Bootgly\ACI\Tests\Benchmark\Opponent;

/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'MyServer',
   version: fn () => 'v1.0.0',
   script: __DIR__ . '/myserver.php',
));
```

The `name` is used as the CLI filter value (lowercased): `--opponents=myserver`.

#### 4. Run

```bash
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,myserver --loads=techempower:*
```

---

## ⚙️ Configuration

### Router & load set

The active load set is chosen with the **required** `--loads=<set>:<indexes>` option
(e.g. `techempower:*`, `benchmark:1,8`). The Bootgly opponent derives the matching
server SAPI from `<set>`, so no env is needed; `BOOTGLY_HTTP_SERVER_CLI_ROUTER` stays
as an optional override.

| Option / Env | Values | Selects |
|--------------|--------|---------|
| `--loads=<set>:<indexes>` (required) | set: `techempower` \| `benchmark`; indexes: `*` or `1,2,…` | load set + 1-based loads to run |
| `BOOTGLY_HTTP_SERVER_CLI_ROUTER` (override) | `techempower`, `bootgly` | which SAPI the server mounts (default: derived from `<set>`) |

PostgreSQL loads also read `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`DB_SSLMODE`, and `DB_POOL_MAX` (see [Database Benchmark](#database-benchmark)).

### Multi-dimensional Vary (`--vary`)

The `--vary` option runs the benchmark across a **cartesian product** of parameter
values, producing one round per combination.

#### Syntax

```bash
--vary=key1:value1,key2:value2,...
```

#### Available dimensions

| Key | Description |
|-----|-------------|
| `server-workers` | Number of server worker processes |
| `connections` | Number of TCP connections |
| `client-workers` | Number of client worker processes |

#### Examples

```bash
# 2D: vary server workers and connections
./bootgly test benchmark HTTP_Server_CLI \
   --vary=server-workers:4,connections:256

# 3D: full cartesian product
./bootgly test benchmark HTTP_Server_CLI \
   --vary=server-workers:4,connections:256,client-workers:8
```

Round headers show only the dimensions that actually vary (e.g. `4sw/256c` instead
of `4sw/256c/0cw`).

### Contextual Help

The benchmark CLI provides a two-tier help system.

#### Tier 1 — List available cases

```bash
./bootgly test benchmark --help
```

Shows all discovered benchmark cases.

#### Tier 2 — Case-specific options

```bash
./bootgly test benchmark HTTP_Server_CLI --help
```

Shows:

- Case configuration (from `@.php`)
- Runner-specific options (from the selected runner's `options()` method)
- Case-local options (from `options.php`, e.g. `--server-workers`)

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory:

```bash
cd /path/to/bootgly
```

### Basic usage

The Bootgly-internal `benchmark` set runs Bootgly-only — pass `--loads=benchmark:*` (the
opponent serves the `bootgly` router automatically):

```bash
# Bootgly-internal benchmark set, all loads (TCP_Client runner)
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=benchmark:*
```

Cross-framework comparison uses the `techempower` set plus a TechEmpower
opponent. The DB routes need PostgreSQL — export `DB_*` (see
[Database Benchmark](#database-benchmark)):

```bash
# Bootgly vs Swoole TechEmpower, six TechEmpower routes
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,swoole-techempower --loads=techempower:*
```

### Filtering loads

```bash
# Run only load 1 (static single) and 8 (nested 6) of the benchmark set
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=benchmark:1,8

# Run only the 100-route loads (3 and 6)
./bootgly test benchmark HTTP_Server_CLI --loads=benchmark:3,6
```

### Runner options (TCP_Client)

```bash
# Custom connections and duration
./bootgly test benchmark HTTP_Server_CLI --connections=256 --duration=15

# With pipelining and explicit client workers
./bootgly test benchmark HTTP_Server_CLI --pipeline=4 --client-workers=8
```

### Case-specific options

```bash
# Set server workers explicitly
./bootgly test benchmark HTTP_Server_CLI --server-workers=4
```

### Global options

| Option | Description |
|--------|-------------|
| `--opponents=NAME,...` | Filter opponents by name |
| `--loads=<set>:<indices>` | **Required.** Load set + 1-based indices (`<set>:*` for all, `<set>:1,2` to filter) |
| `--vary=KEY:VALUE,...` | Multi-dimensional benchmarking (see [Configuration](#-configuration)) |

---

## 🩺 Probes

The optional ADI Database probe verifies that a PostgreSQL operation suspended
through Bootgly's native event loop does **not** block a single `HTTP_Server_CLI`
worker.

It starts a one-worker Bootgly server, generates load against `/load` with
Bootgly's own `TCP_Client_CLI` benchmark worker, opens `/db-sleep`
(`SELECT pg_sleep(...)`), confirms the query is active through `pg_stat_activity`,
then requests `/fast`. The probe passes only when `/fast` responds quickly while
PostgreSQL is still sleeping.

### Requirements

- `psql` in `PATH`
- a reachable PostgreSQL database
- sibling repository layout: `bootgly/` and `bootgly_benchmarks/`
- the Demo HTTP Server CLI database config enabled through environment variables

### Example

Run from the `bootgly` repository:

```bash
DB_ENABLED=true \
DB_HOST=127.0.0.1 \
DB_PORT=55432 \
DB_NAME=bootgly \
DB_USER=postgres \
DB_PASS= \
DB_SSLMODE=disable \
DB_SSLVERIFY=false \
php ../bootgly_benchmarks/HTTP_Server_CLI/probes/async_database.php
```

Expected success includes:

```text
PostgreSQL active pg_sleep seen: yes (active=1)
Fast response during active DB: HTTP 200 in 0.000392s
Load output: {"rps":"231.36 req\/s","latency":"250.34ms","transfer":"30.73KB\/s"}
PASS: database wait did not block the single HTTP worker.
```

### Probe variables

| Variable | Default | Description |
|----------|---------|-------------|
| `BOOTGLY_ADI_PROBE_PORT` | `18082` | Probe HTTP server port |
| `BOOTGLY_ADI_PROBE_CONNECTIONS` | `64` | TCP client connection count |
| `BOOTGLY_ADI_PROBE_CLIENT_WORKERS` | `2` | TCP client worker processes |
| `BOOTGLY_ADI_PROBE_DURATION` | `12` | TCP client duration in seconds |
| `BOOTGLY_ADI_PROBE_PIPELINE` | `1` | HTTP pipelining factor |
| `BOOTGLY_ADI_PROBE_DB_SLEEP` | `2` | PostgreSQL `pg_sleep()` duration |
| `BOOTGLY_ADI_PROBE_FAST_MAX` | `0.5` | Maximum accepted `/fast` latency in seconds |
| `BOOTGLY_ADI_PROBE_LOAD_DELAY` | `0.25` | Deferred `/load` response delay in seconds |

---

## ⚠️ Environment Notes

- **CPU balance**: both the load generator and the server share CPU. The default uses `nproc / 2` workers to leave cores for the load generator.
- **TCP_Client runner**: uses Bootgly's built-in TCP client — no external tooling required. Loads are PHP scripts under `loads/techempower/` or `loads/benchmark/`.
- **Docker opponents**: every cross-framework opponent runs in a container (`--network host`, binding `:8082` directly); it is started in the foreground (the container is the daemon) and stopped with `docker rm -f <container>`. `lsof` may not see the in-container listener (this also covered the old native Go binaries, FrankenPHP / RoadRunner, which now run inside images), so the runner uses `curl` polling on `:8082` as readiness/liveness fallback.
- **Localhost loopback**: all tests run on `127.0.0.1` — network latency is not a factor.
- **Swoole extension**: must be compiled with CLI support. Verify with `php -m | grep swoole`.
- **Hyperf**: requires Swoole with `swoole.use_shortname=Off`. Add to `php.ini` before running.
- **Result variance**: results vary by hardware, OS, PHP version, and system load. Always compare on the **same machine, same session**.
- **Warmup**: a warmup phase runs before each benchmark to stabilize JIT, TCP buffers, and worker pools.

---

## 📊 Results

### Terminal output

Single opponent — one table, one row per load:

```
  Bootgly
  Load                Metric              Latency         Transfer
  Plaintext           133,625 req/s       790.83us        16.82MB/s
  JSON                131,458 req/s       803.32us        19.06MB/s
```

Multiple opponents — one block per load, each opponent compared to the Bootgly
baseline:

```
  Bootgly vs Swoole TechEmpower

  ── Plaintext ──
  Opponent          Metric              Latency         Transfer        vs Bootgly
  Bootgly             147,163 req/s       708.04us        18.53MB/s       baseline
  Swoole TechEmpower  115,110 req/s       925.85us        18.33MB/s       -21.8%
```

- **vs Bootgly** = `((Bootgly − Opponent) / Opponent) × 100` — a negative value means the opponent is slower than Bootgly.
- **Latency** = average response time.

### Saved marks

Each run saves a plain-text `.bench.marks` file (easy to diff or archive) — the
input to the chart tooling:

```
bootgly/storage/tests/benchmarks/HTTP_Server_CLI/<timestamp>_bench.marks
```

### Reports

Reports are **auto-generated** from a range of `.bench.marks` files by
`bootgly_benchmarks/scripts/chart.py` into this case's `results/` folder:

```
results/RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.md
results/RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.throughput.png
results/RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.ratio.png
```

Each report carries an environment block (auto-detected OS, CPU, PHP, Swoole), a
reproduction command, throughput/ratio charts, per-load comparison tables, and a
peaks summary.

#### Latest reports

**TechEmpower — per opponent.** Each sweep compares **Bootgly** (baseline) vs one
opponent across a `server-workers` sweep at `DB_POOL_MAX=1`. The throughput chart is
shown; click the opponent for the full report (environment block, reproduction
command, per-load tables, ratio chart, peaks). Results live under
`results/<opponent>/`.

**Swoole (TechEmpower)** — [report](results/swoole/RESULTS-techempower-2026-06-22_172346.md)

![Bootgly vs Swoole — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/swoole/RESULTS-techempower-2026-06-22_172346.chart.throughput.png)

![Bootgly vs Swoole — TechEmpower latency](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/swoole/RESULTS-techempower-2026-06-22_172346.chart.latency.png)

**Hyperf** — [report](results/hyperf/RESULTS-techempower-2026-06-24_103419.md)

![Bootgly vs Hyperf — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/hyperf/RESULTS-techempower-2026-06-24_103419.chart.throughput.png)

![Bootgly vs Hyperf — TechEmpower latency](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/hyperf/RESULTS-techempower-2026-06-24_103419.chart.latency.png)

**ReactPHP** — [report](results/reactphp/RESULTS-techempower-2026-06-25_161542.md)

![Bootgly vs ReactPHP — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/reactphp/RESULTS-techempower-2026-06-25_161542.chart.throughput.png)

**AMPHP** — [report](results/amphp/RESULTS-techempower-2026-06-25_161540.md)

![Bootgly vs AMPHP — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/amphp/RESULTS-techempower-2026-06-25_161540.chart.throughput.png)

**Laravel (nginx / Apache, PHP-FPM)** — [report](results/laravel-fpm/RESULTS-techempower-2026-06-22_233037.md)

![Bootgly vs Laravel FPM — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-fpm/RESULTS-techempower-2026-06-22_233037.chart.throughput.png)

**Laravel (Octane)** — [report](results/laravel-octane/RESULTS-techempower-2026-06-22_235421.md)

![Bootgly vs Laravel Octane — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-octane/RESULTS-techempower-2026-06-22_235421.chart.throughput.png)

**Laravel (OpenLiteSpeed + LSCache)** — [report](results/laravel-ols/RESULTS-techempower-2026-06-24_120235.md)

![Bootgly vs Laravel OLS — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-ols/RESULTS-techempower-2026-06-24_120235.chart.throughput.png)

#### 1. One-time setup

```bash
cd bootgly_benchmarks/scripts
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
```

Requires Python ≥ 3.10 and matplotlib. The venv only has to be built once.

#### 2. Run a sweep

The X axis is the config key that varies between input files (normally
`server-workers`), so charts need a **sweep** — at least two runs differing in one
parameter. Run from the **bootgly** directory.

TechEmpower set, Bootgly vs Swoole, all six routes:

```bash
cd bootgly
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=bootgly DB_USER=postgres \
       DB_PASS='' DB_SSLMODE=disable DB_POOL_MAX=3
for sw in 1 2 4 8 12 24; do
   php bootgly test benchmark HTTP_Server_CLI \
      --opponents=bootgly,swoole-techempower --runner=tcp_client \
      --connections=512 --duration=10 --server-workers="$sw" --loads=techempower:1,2,3,4,5,6
done
```

Bootgly-internal set (no opponents), e.g. just the DB probes:

```bash
cd bootgly
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=bootgly DB_USER=postgres \
       DB_PASS='' DB_SSLMODE=disable DB_POOL_MAX=3
for sw in 1 2 4 8 12 24; do
   php bootgly test benchmark HTTP_Server_CLI \
      --opponents=bootgly --runner=tcp_client \
      --connections=512 --duration=10 --server-workers="$sw" --loads=benchmark:13,14,15,16,17,18,19,20
done
```

> A single-parameter sweep can also be produced in one command with
> [`--vary`](#-configuration), e.g. `--vary=server-workers:1,server-workers:2,...`.
> The `for` loop above is the explicit equivalent.

#### 3. Generate the charts

Point `--marks` at a glob covering **every** `.marks` file from the sweep, and
`--out` at this case's `results/` directory:

```bash
cd bootgly_benchmarks/scripts
.venv/bin/python3 chart.py \
   --marks '../../bootgly/storage/tests/benchmarks/HTTP_Server_CLI/2026-06-04_*_bench.marks' \
   --out ../HTTP_Server_CLI/results/ \
   --baseline Bootgly
```

#### Tips & gotchas

- **The glob must match every file in the sweep.** A pattern like `2026-06-04_193*`
  silently drops `19:34:xx` runs — prefer `2026-06-04_19*` or `2026-06-04_*`.
  `chart.py` aborts with *"No config key varies"* when the glob resolves to a single
  file (nothing to put on the X axis).
- **Subplots follow `--loads`.** The chart draws one subplot per load present in the
  marks, so the `--loads` you ran during the sweep decide which subplots appear.
- **X axis is auto-detected** (the config key with the most distinct values). Force
  it with `--x-key server-workers` if several keys vary.
- **Baseline** defaults to the first opponent alphabetically; pass
  `--baseline Bootgly` to pin it (drives the ratio chart and `Δ` columns).
- **Filenames** use the marks `# Config: load-set` key (`techempower` or
  `benchmark`), so the two sets never overwrite each other.

See [`scripts/README.md`](../scripts/README.md) for the full `chart.py` CLI reference.
