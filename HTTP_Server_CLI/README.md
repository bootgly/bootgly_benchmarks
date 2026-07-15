# ⏱️ Benchmark — HTTP Server CLI

Benchmarks for **Bootgly HTTP Server CLI**, split into two **load sets**:

- **`techempower`** — the seven canonical TechEmpower routes (`/plaintext`, `/json`, `/db`, `/query`, `/fortunes`, `/updates`, `/cached-queries`), served identically across frameworks for a **fair cross-framework comparison**. Every cross-framework opponent ships as a **self-contained Docker image** (`bootgly/bootgly_benchmarks:<opponent>`) that bundles Bootgly + the opponent runtime + PostgreSQL and runs the whole benchmark in-process from a single `docker run` — zero host setup. **Bootgly** is the in-image baseline (also runnable natively, pure PHP, no system deps).
- **`benchmark`** — a Bootgly-internal stress surface (100 static, 100 dynamic, nested groups, per-route middleware, catch-all, plus DB probes) for **self-comparison during framework development**. Bootgly-only.

Select the set in the **required** `--loads=<set>:<indexes>` option — `<set>` is `techempower` or `benchmark`, `<indexes>` is `*` (all) or a comma list (e.g. `techempower:1,2`). The Bootgly opponent derives the matching server router from the load set automatically.

---

## 🐳 Quickstart (0 setup with Docker)

**One command. No install, no config, no database setup.** Copy, paste, run — it pulls a
self-contained image (Bootgly + the opponent + PostgreSQL, which **boots and seeds itself**)
and prints the full benchmark: Bootgly vs the opponent, static **and** database routes.

Benchmark Bootgly against **Swoole** using the `TechEmpower` load set:

```bash
docker run --rm bootgly/bootgly_benchmarks:swoole \
   test benchmark HTTP_Server_CLI --opponents=bootgly,swoole --loads=techempower:*
```

The only requirement is a running Docker daemon.

### Run another opponent

Same command — replace `<image>` with the opponent's image tag and `<opponent>` with its name:

```bash
docker run --rm bootgly/bootgly_benchmarks:<image> \
   test benchmark HTTP_Server_CLI --opponents=bootgly,<opponent> --loads=techempower:*
```

| `<image>` | `<opponent>` | Published |
|-----------|--------------|:---------:|
| `swoole` | `swoole` | ✅ |
| `workerman` | `workerman` | ✅ |
| `reactphp` | `reactphp` | ✅ |
| `amphp` | `amphp` | ✅ |
| `roadrunner` | `roadrunner` | ✅ |
| `hyperf` | `hyperf` | ✅ |
| `laravel-octane` | `laravel-octane` | ✅ |
| `frankenphp` | `frankenphp` | ✅ |

> ✅ published to Docker Hub. `frankenphp` bundles the official static binary, which already
> embeds `pdo_pgsql`/`pgsql`, so its TechEmpower DB routes run in the self-contained image.

### Common tweaks

- **Tuning** passes straight through: `--server-workers=15 --connections=512 --duration=10`.
- **Static only** (skip the DB): `--loads=techempower:1,2` (plaintext + JSON).
- **Override the DB** (use an external PostgreSQL instead of the bundled one):
  `-e DB_HOST=… -e DB_PORT=… -e DB_NAME=… -e DB_USER=… -e DB_PASS=…`.

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
| **Docker** | Runs the self-contained opponent image (`bootgly/bootgly_benchmarks:<opponent>`) — Bootgly + opponent runtime + PostgreSQL, all in one `docker run`. | Only for cross-framework |
| **Swoole / Workerman / RoadRunner / Hyperf / ReactPHP / AMPHP runtimes** + `pdo_pgsql` | Baked into the Docker image (no host install) | — |

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

### 1. Cross-framework opponents (self-contained Docker images)

> Every cross-framework opponent ships as **one self-contained image** —
> `bootgly/bootgly_benchmarks:<opponent>` — that bundles Bootgly, the opponent runtime
> and PostgreSQL. The image **boots and seeds its own database** and runs every server
> **in-process on loopback** (no docker-in-docker, no `--network host`), so the whole
> benchmark is a single `docker run` with **zero host setup**:
>
> ```bash
> docker run --rm bootgly/bootgly_benchmarks:swoole \
>    test benchmark HTTP_Server_CLI --opponents=bootgly,swoole --loads=techempower:*
> ```

Published images (Docker Hub, `bootgly/bootgly_benchmarks:<tag>`):

| `<tag>` | Opponent name(s) (`--opponents=`) | Runtime baked in |
|---------|-----------------------------------|------------------|
| `swoole` | `swoole` | Swoole + `pdo_pgsql` |
| `workerman` | `workerman` | Workerman v5 (pure PHP) |
| `reactphp` | `reactphp` | ReactPHP + `voryx/pgasync` |
| `amphp` | `amphp` | Amp v3 + ext-pgsql |
| `roadrunner` | `roadrunner` | RoadRunner (Go) + `pdo_pgsql` |
| `hyperf` | `hyperf` | Hyperf (Swoole) + `pdo_pgsql` |
| `laravel-octane` | `laravel-octane` | Laravel Octane on Swoole |
| `frankenphp` | `frankenphp` | FrankenPHP static binary (embeds `pdo_pgsql`/`pgsql`) |

`bootgly` is the baseline opponent and is baked into **every** image (pass it alongside the
opponent: `--opponents=bootgly,<opponent>`). Worker count is read from `BOOTGLY_WORKERS`
(default `nproc / 2`); override the bundled DB with `-e DB_HOST=… -e DB_PORT=… -e DB_NAME=…
-e DB_USER=… -e DB_PASS=…`.

> **FrankenPHP runs the DB routes.** The official FrankenPHP static binary embeds its own ZTS
> PHP with `pdo_pgsql`/`pgsql` already compiled in, so the TechEmpower DB routes run in the
> self-contained image — the worker opens one persistent raw PDO to the bundled PostgreSQL.

### 2. Bootgly natively (optional)

`bootgly` also runs **without Docker** as a pure-PHP baseline. `bootgly` and
`bootgly_benchmarks` must sit **side by side** in the same parent directory — the runner
resolves the framework via that relative layout — and you need a seeded host PostgreSQL
(see the throwaway-instance note under Prerequisites):

```bash
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=techempower:*
```

---

## 🎯 Loads

The case ships **two load sets**, selected with the required `--loads=<set>:<indexes>`
option. The Bootgly opponent derives the matching server SAPI from `<set>`
automatically. A *load* is one PHP file
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
`projects/Benchmark/HTTP_Server_CLI/router/bootgly-benchmark.SAPI.php`. The
route loads (static, dynamic, nested, middleware, mixed) are tagged
`@opponents: all` — cross-framework opponents serve the same route set from
their generic bootables. Only the DB probe loads are tagged
`@opponents: Bootgly`, so the runner skips other opponents on them
automatically — even if you pass `--opponents=bootgly,swoole`.

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
| 10 | `4.1.1-database_native_ping` | DB probe | `/database/native/ping` |
| 11 | `4.1.2-database_runner_ping` | DB probe | `/database/resource/ping` |
| 12 | `4.2.1-database_native_parameters` | DB probe | `/database/native/parameters` |
| 13 | `4.2.2-database_runner_parameters` | DB probe | `/database/resource/parameters` |
| 14 | `4.3.1-database_native_pool` | DB probe | `/database/native/pool` |
| 15 | `4.3.2-database_runner_pool` | DB probe | `/database/resource/pool` |
| 16 | `4.4.1-database_native_sleep` | DB probe | `/database/native/sleep` |
| 17 | `4.4.2-database_runner_sleep` | DB probe | `/database/resource/sleep` |
| 18 | `z.1.1-mixed_8` | Mixed | 5 static + 3 dynamic |
| 19 | `z.1.2-mixed_20` | Mixed | 10 static + 10 dynamic |
| 20 | `z.1.3-full_mix` | Mixed | static + dynamic + nested + middleware + 404 |

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
run against every selected cross-framework opponent whose database-connection
capability satisfies the harness parity contract:

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

Compare Bootgly with the Swoole opponent:

```bash
DB_HOST=127.0.0.1 \
DB_PORT=5432 \
DB_NAME=bootgly \
DB_USER=postgres \
DB_PASS= \
DB_SSLMODE=disable \
DB_SSLVERIFY=false \
DB_POOL_MAX=1 \
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,swoole --runner=tcp_client --connections=1024 --duration=10 --server-workers=24 --client-workers=4 --loads=techempower:3,4,5,6
```

On the `techempower` set, the Swoole opponent requires PHP extensions `swoole` and `pdo_pgsql`
(`php8.4-pgsql` on this machine). It follows the TechEmpower Swoole PostgreSQL
pattern with `Swoole\Database\PDOPool` and does not use Bootgly runtime code.

#### Fairness & caveats

> **Database pool parity is fail-closed.** For the `techempower` set, an omitted or
> empty `DB_POOL_MAX` is materialized as `1` before any server process starts. The
> effective value is recorded in result metadata and displayed in the banner. For
> DB loads `3–7`, the harness accepts a run only after matching every selected
> opponent to a source-inspected capability contract. Bootgly, Swoole, AMPHP,
> ReactPHP, and Hyperf honor a configurable per-worker ceiling. Workerman,
> RoadRunner, FrankenPHP, and Laravel Octane are fixed at one persistent database
> connection per worker. Consequently, `DB_POOL_MAX=1` is the common comparable
> setting; values greater than one are accepted only when every selected opponent
> is pool-aware. Unknown or unclassified opponents are rejected for DB comparisons
> until their behavior is inspected and registered.

The contract validates the configured per-worker ceiling. It does not claim that
the maximum number of PostgreSQL sessions was simultaneously open during a run.
Legacy artifacts that record only `db-pool-max`, without
`db-pool-comparability=capability-validated-v1`, therefore do not prove effective
parity by themselves.

> **`/updates` at very high worker counts is not yet characterized.** On a many-core host (e.g.
> 48 server-workers / 96 threads) Bootgly's `/updates` can collapse to a few hundred req/s:
> concurrent batched `UPDATE`s deadlock in PostgreSQL (~1 s `deadlock_timeout` stalls; confirmed
> in the PG log). Bootgly **wins `/updates` at ≤~24–32 workers**. This collapse persists even at
> `DB_POOL_MAX=1`, so it is **not** purely a pool-size effect — the high-concurrency behaviour
> (and why a blocking-PDO opponent avoids it) needs validation on real high-core hardware before
> `/updates` is published as a settled result.

#### Tuning notes

From WSL2 / Ryzen 9 3900X / PostgreSQL `max_connections=100`:

- `DB_POOL_MAX=1` per HTTP worker was fastest for short microbenchmarks.
- On a single machine running client, server, and PostgreSQL together,
  `--server-workers=24` + `--client-workers=4` is the best balanced full-suite
  default observed so far.
- `--server-workers=28` + `--client-workers=5` favored peak ping/parameter
  throughput, while 24/4 left more CPU headroom for pool-heavy loads.
- `DB_POOL_MAX=1` is the universal cross-opponent comparison setting. Values above
  one are tuning experiments valid only when all selected opponents are among the
  pool-aware implementations; the harness rejects fixed-one opponents in that case.
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

### Exact HTTP response accounting

The timed TCP_Client worker keeps one incremental HTTP/1 framing tracker per
connection. It carries parser state across arbitrary reads, associates response
semantics with the FIFO request method, and counts a response only after its
final framing boundary is observed. The handled framing includes informational
responses, HEAD/204/304, Content-Length, chunked bodies and trailers,
close-delimited bodies, pipelined responses, malformed input, and truncated
input. Body bytes are discarded after framing; marker text such as `HTTP/1.`
inside a body is not a response.

Pipeline 1 has a correctness-preserving fast path for the common fixed-length
response. The first response on a connection still passes through the strict
parser. A later atomic read is accepted by the fast path only when its complete
head is byte-identical to that validated head, its request method matches, and
its total frame length is exact. Every other shape enters the incremental path:
a changed head or method reaches the strict parser untouched, while matching
fragmented or pipelined cached frames may use only the already-validated cached-
head framing path. Chunked framing remains generic. Complete synchronous request
writes use scalar accounting; partial writes retain the exact byte-boundary
ledger and unsent suffix. Cached status counts are merged when the validated
head changes or results are inspected. In the retained idle comparison, these
changes reduced the initial observed strict-accounting regression to a 2.79%
mean residual without returning to status-token counting; that session result
is not a universal overhead bound.

Every saved timed result exposes `scheduled`, `sent`, `responses`,
`informational`, `outstanding`, `failed`, `write_failed`, `connection_failed`,
`partial_writes`, per-status totals, response-failure totals, write-failure
totals, and `accounting`. A valid terminal result must satisfy all of these
conditions:

```text
scheduled = sent + sum(write_failures)
sent = responses + sum(failures)
outstanding = 0
responses = sum(statuses)
connection_failed = 0
```

The parent accepts only the files assigned to the child PIDs it actually
spawned and revalidates each child before aggregation. Missing, malformed,
non-finite, nonzero-exit, or internally inconsistent child output makes the
result `accounting=invalid`; throughput is then emitted as `null`/`N/A` rather
than inferred from partial data. `measurement_ended` is the explicit terminal
classification for fully sent requests still in flight when the duration timer
closes the window; it is not by itself evidence of a server fault.

`partial_writes` counts observed reconciliations that leave a queued suffix; it
is not a count of every underlying `fwrite()` syscall. The current latency value
is still the older per-socket/burst approximation. It must not be interpreted as
per-logical-request latency under pipelining; monotonic request timestamps and
mergeable percentiles remain separate harness work.

---

## 🏁 Opponents

| Server | Runtime | TFB routes | Docker image (`bootgly/bootgly_benchmarks:`) |
|--------|---------|-----------|--------------|
| **Bootgly** HTTP Server CLI | PHP (event-loop) | 7 | baked into every image (also native) |
| **Swoole** | PHP (C extension), `SWOOLE_BASE` reactor per worker + PDO pool | 7 | `swoole` |
| **Hyperf** | PHP (Swoole framework), full-stack coroutine | 7 (`/cached-queries` = per-worker in-memory `CachedWorld` cache) | `hyperf` |
| **Workerman** v5 | PHP (event-loop), sync per-worker PDO | 7 | `workerman` |
| **RoadRunner** | Go + PHP (goridge), PSR-7 worker, per-worker PDO | 7 | `roadrunner` |
| **ReactPHP** | PHP (pure-async event loop), fork N + `SO_REUSEPORT`, async PG via `voryx/pgasync` | 7 | `reactphp` |
| **AMPHP** | PHP (Amp v3 fibers, pure-async), fork N + `SO_REUSEPORT`, async PG via `amphp/postgres` | 7 | `amphp` |
| **Laravel** (Octane) | Laravel Octane on Swoole, persistent workers | 6 (no `/cached-queries`) | `laravel-octane` |
| **FrankenPHP** | Go + PHP worker mode (Caddy-based), per-worker persistent raw PDO | 7 | `frankenphp` |

Event-loop opponents use `nproc / 2` workers for fair CPU distribution. **Laravel Octane**
runs persistent Swoole workers (1:1 with server-workers), each holding one persistent PDO
connection — matching the pooled servers' per-worker DB footprint.

All published opponents — `swoole`, `hyperf`, `workerman`, `roadrunner`, `reactphp`,
`amphp` — implement all **seven** TechEmpower routes (including `/cached-queries`).
**Laravel Octane** serves **six** (`/cached-queries` is deliberately **N/A** — see below).

> **Swoole Process / Coroutine** modes are not standalone opponents — they live on as
> bootables (`bootables/swoole/swoole-process-routes.php`, `…coroutine-routes.php`) for
> ad-hoc mode comparisons. The `swoole` image ships the **one** `swoole` opponent, always
> in `SWOOLE_BASE` mode; its bootable is derived from the load set (techempower → the
> TFB PDO bootable, anything else → the generic route set).

> **Laravel** dropped its FPM/web-server stacks (`laravel-nginx`, `laravel-apache`,
> `laravel-ols`): nginx/Apache need PHP-FPM (the base image is CLI-only) and OpenLiteSpeed
> needs its own base — neither fits the single-foreground-server, `FROM bootgly:full`
> self-contained model. **Octane** (foreground Swoole) is the one that fits.

> `/cached-queries` is **N/A** for Laravel Octane to keep parity with the (now dropped)
> per-request FPM Laravel stacks, where a fresh worker per request can hold no in-process
> cache. The persistent-worker opponents (swoole, hyperf, workerman, roadrunner, reactphp,
> amphp) implement all seven.

### Available names

CLI filter values for `--opponents=`:

| Name | Description (image = `bootgly/bootgly_benchmarks:<tag>`) |
|------|-------------|
| `bootgly` | Bootgly HTTP Server CLI — baseline, baked into every image (also runnable natively), all 7 TechEmpower routes |
| `swoole` | Swoole `SWOOLE_BASE` mode (tag `swoole`) — techempower set = TFB bootable with PDO pool (all 7 routes); benchmark set = generic route set |
| `hyperf` | Hyperf, Swoole framework (tag `hyperf`) — all 7 routes (`/cached-queries` = per-worker in-memory cache) |
| `workerman` | Workerman v5, sync per-worker PDO (tag `workerman`) — all 7 routes |
| `roadrunner` | RoadRunner (Go + PHP), PSR-7 worker, per-worker PDO (tag `roadrunner`) — all 7 routes |
| `reactphp` | ReactPHP pure-async event loop, fork N + `SO_REUSEPORT`, async PG via `voryx/pgasync` (tag `reactphp`) — all 7 routes |
| `amphp` | AMPHP (Amp v3 fibers) pure-async, fork N + `SO_REUSEPORT`, async PG via `amphp/postgres` (tag `amphp`) — all 7 routes |
| `laravel-octane` | Laravel Octane on Swoole, persistent workers (tag `laravel-octane`) — 6 routes (no `/cached-queries`) |
| `frankenphp` | FrankenPHP worker mode (Caddy + embedded ZTS PHP), per-worker persistent raw PDO — the static binary embeds `pdo_pgsql`/`pgsql` (tag `frankenphp`) — all 7 routes |

### Laravel opponent (`laravel-octane`)

Laravel (app in `bootables/laravel/`) served by **Octane on Swoole** — persistent
in-memory workers (no per-request bootstrap), the fast Laravel stack. It runs in-process
inside the self-contained `bootgly/bootgly_benchmarks:laravel-octane` image, which bakes
everything in (`composer install --no-dev --optimize-autoloader`, Swoole, `pdo_pgsql`):

```bash
docker run --rm bootgly/bootgly_benchmarks:laravel-octane \
   test benchmark HTTP_Server_CLI --opponents=bootgly,laravel-octane --loads=techempower:*
```

Octane workers map 1:1 to server-workers, each holding one persistent Eloquent/PDO
connection — matching the pooled servers' `DB_POOL_MAX=1` per-worker DB footprint. Tuning
(env): `BENCHMARK_PORT` (default `8082`), `BOOTGLY_WORKERS` (default `nproc / 2`). The
Bootgly `DB_*` set (`DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS`) is remapped to
Laravel's `DB_CONNECTION=pgsql` + `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`; the
entrypoint sets `APP_ENV=production` and `XDEBUG_MODE=off` (Xdebug is not installed at all —
in any SAPI it overrides `zend_execute_ex`, kills JIT and cuts throughput several-fold).

> The previously-included per-request Laravel stacks (`laravel-nginx`, `laravel-apache`,
> `laravel-ols`) were dropped from the self-contained set — they need PHP-FPM / a dedicated
> web server (or, for OLS, its own base image) that don't fit the single-foreground-server
> `FROM bootgly:full` model. Octane (foreground Swoole) is the Laravel stack that fits.

Absolute throughput is environment-dependent — run the suite on your own hardware.

### Adding an opponent

#### 1. Create the opponent script

Each opponent lives in its own folder under `opponents/` with **one** script —
one canonical opponent per server (e.g. `opponents/swoole/swoole.php` derives
its bootable from the active load set). Create `opponents/myserver/myserver.php`
— it starts the HTTP server and blocks until killed:

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

#### 3. Self-register via `opponents/myserver/autoboot.php`

Each opponent folder ships an `autoboot.php` that registers itself — the case's main
`autoboot.php` auto-discovers them with `glob(opponents/*/autoboot.php)`, so you never edit it.
A folder may register several variations (see `opponents/swoole/autoboot.php`):

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
server SAPI from `<set>` automatically — no env needed.

| Option | Values | Selects |
|--------------|--------|---------|
| `--loads=<set>:<indexes>` (required) | set: `techempower` \| `benchmark`; indexes: `*` or `1,2,…` | load set + 1-based loads to run |

PostgreSQL loads also read `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`,
`DB_SSLMODE`, and `DB_POOL_MAX` (see [Database Benchmark](#database-benchmark)).
For `techempower`, the harness defaults a missing/empty `DB_POOL_MAX` to `1`, records
the effective value, and validates it against the selected opponents before starting
any measured process.

### Sweeps

Options declared with `vary: true` in the case `options.php` schema — here,
`--server-workers` — accept **sweep values**. Each expanded value becomes one
execution **round** in the same process, so a full `server-workers` sweep runs
from a single command (including inside a single `docker run`), producing one
`.bench.marks` file per round.

#### Sweep grammar

| Value | Expands to |
|-------|-----------|
| `8` | `[8]` (single round — same as before) |
| `1..24` | `[1, 2, …, 24]` |
| `1..24:4` | `[1, 5, 9, 13, 17, 21]` (range with step) |
| `1,2,4,8` | `[1, 2, 4, 8]` (explicit list) |

#### Examples

```bash
# Full server-workers sweep, 24 rounds, one .marks per round:
./bootgly test benchmark HTTP_Server_CLI \
   --opponents=bootgly,swoole --loads=techempower:1,2 --server-workers=1..24

# Coarse sweep with native charts (see --results below):
./bootgly test benchmark HTTP_Server_CLI \
   --opponents=bootgly,swoole --loads=techempower:1,2 \
   --server-workers=1..24:4 --results=charts
```

> A sweep multiplies wall-clock time: rounds × opponents × loads × (`--duration`
> + warmup/readiness overhead). Use `--duration=3` for exploratory sweeps.
> Long sweeps can also accumulate client-side `TIME_WAIT` sockets; the runner's
> port cleanup and preflight retries absorb this, but very dense sweeps benefit
> from a pause between runs.

### Output, format and results

Three global options control what a run prints and generates:

| Option | Values | Effect |
|--------|--------|--------|
| `--output` | `full` \| `compact` (default: auto) | Output style. `compact` prints the banner/system/opponents blocks once and a short header per round — the automatic choice when sweeping. |
| `--format` | `text` \| `json` (default: `text`) | Results serialization. `json` is supervised and writes exactly one machine-readable document to public stdout on normal or otherwise handled success/failure; child stdout/stderr remains in the run workspace, so that completed stream pipes directly into `jq`. External termination/crash is not covered by this guarantee. |
| `--results` | `marks` \| `report` \| `charts` (default: `marks`) | Generated artifacts (inclusive levels). `report` also writes a `RESULTS-<load-set>-<run-token>.md`; `charts` adds native SVG charts (throughput / ratio / latency) — no Python required. |

Every invocation exclusively claims a collision-resistant workspace:

```text
bootgly/storage/tests/benchmarks/<case>/runs/<run-id>/
├── manifest.json
├── result.json                 # --format=json
├── marks/
├── reports/                    # --results=report|charts
├── logs/                       # supervised harness channels
└── runners/                    # when the runner starts child processes
```

Harness-owned terminal files and completed stdout/stderr pairs are staged and
atomically published inside that workspace. Framework-specific logs written
while a server is live become stable after its tracked join and are then hashed.
Readiness requires an HTTP application response rather than an open TCP port.
For the official Swoole opponent, the foreground wrapper must also remain alive
and the run-local `swoole.pid` must identify the same non-zombie process (PID,
Linux `/proc` start time, and expected bootable) before and after the HTTP probe.
Consequently, a listener left on the shared port cannot be attributed to a new
Swoole invocation that failed to bind.
In text mode the final **Artifacts** block identifies
the run, path base, marks, manifest and any report/chart; in JSON mode the same
identity and paths are embedded in the document (`run`, `rounds[].marks`, and
`artifacts`). Raw run directories are retained until explicitly removed; the
harness does not currently implement automatic retention or pruning. Archive
or remove complete inactive `runs/<run-id>` directories rather than individual
artifacts.

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

- Case configuration (from `autoboot.php`)
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
# Bootgly vs Swoole, six TechEmpower routes
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly,swoole --loads=techempower:*
```

### Filtering loads

```bash
# Run only load 1 (static single) and 8 (nested 6) of the benchmark set
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=benchmark:1,8

# Run only the 100-route loads (3 and 6)
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly --loads=benchmark:3,6
```

### Runner options - TCP_Client

```bash
# Custom connections and duration
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly \
   --loads=benchmark:1 --connections=256 --duration=15

# With pipelining and explicit client workers
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly \
   --loads=benchmark:1 --pipeline=4 --client-workers=8
```

### Case-specific options

```bash
# Set server workers explicitly
./bootgly test benchmark HTTP_Server_CLI --opponents=bootgly \
   --loads=benchmark:1 --server-workers=4
```

### Global options

| Option | Description |
|--------|-------------|
| `--opponents=NAME,...` | **Required.** Comma-separated opponent names |
| `--loads=<set>:<indices>` | **Required.** Load set + 1-based indices (`<set>:*` for all, `<set>:1,2` to filter) |
| `--server-workers=N\|A..B\|A..B:S\|N,N` | Server workers — sweep values run one round each (see [Sweeps](#sweeps)) |
| `--output=full\|compact` | Output style (default: auto — compact when sweeping) |
| `--format=text\|json` | Results serialization (`json` emits one public document on normal/handled completion; external termination is excluded) |
| `--results=marks\|report\|charts` | Generated artifacts (see [Output, format and results](#output-format-and-results)) |

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
- **Self-contained opponent images**: every cross-framework opponent runs **in-process** inside its `bootgly/bootgly_benchmarks:<tag>` image (the runner spawns each server locally on loopback — no docker-in-docker, no `--network host`). The image boots and seeds its own PostgreSQL. Bootgly and the opponent share `127.0.0.1`, so the comparison is fair and needs zero host setup.
- **Localhost loopback**: all tests run on `127.0.0.1` — network latency is not a factor.
- **Swoole / Hyperf / Laravel Octane**: the Swoole extension (built `swoole.use_shortname=Off`) is baked into the `swoole`, `hyperf` and `laravel-octane` images — no host install.
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
  Bootgly vs Swoole

  ── Plaintext ──
  Opponent          Metric              Latency         Transfer        vs Bootgly
  Bootgly             147,163 req/s       708.04us        18.53MB/s       baseline
  Swoole              115,110 req/s       925.85us        18.33MB/s       -21.8%
```

- **vs Bootgly** = `((Bootgly − Opponent) / Opponent) × 100` — a negative value means the opponent is slower than Bootgly.
- **Latency** = average response time.

### Saved marks

Each run saves a plain-text `.bench.marks` file (easy to diff or archive) — the
input to the chart tooling. Its Config header records the framework and
benchmark-suite Git SHAs plus dirty state. It also records SHA-256 fingerprints
for a raw tracked-byte/executable-mode delta and the internal non-ignored
untracked-input manifest, with `source-identity-version` identifying their
encoding. When
known, those values distinguish dirty worktrees at the same commit without
placing raw patches, paths, or source content in the artifact. A value is
`unknown` when source metadata is unavailable or a live tree cannot be
fingerprinted safely. Git clean/smudge and EOL filters are bypassed. Ignored
files, dependencies, environment, and external runtime state remain outside
this source identity. Live Git identity is captured again after each measured
round, and incomplete or changed provenance prevents that result from being
saved. Git-less packages can only reconfirm their fallback tuple and therefore
require an immutable attributed source layer or mount. A `false` dirty value is
valid only when both fingerprints equal the standard empty SHA-256 digest; an
index-only staged delta can validly produce `true` with both fingerprints empty:

```
bootgly/storage/tests/benchmarks/HTTP_Server_CLI/runs/<run-id>/marks/result_bench.marks
```

### Reports

Native reports are generated from the invocation's collected round data into
its run-local `reports/` folder:

```
runs/<run-id>/reports/RESULTS-<load-set>-<run-token>.md
runs/<run-id>/reports/RESULTS-<load-set>-<run-token>.chart.throughput.svg
runs/<run-id>/reports/RESULTS-<load-set>-<run-token>.chart.ratio.svg
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

> The Bootgly baseline was re-measured on **v0.19.1-beta** (persistent Fiber pool +
> DBAL hot path, 2026-07-04); the opponent series are the previously published runs
> (2026-06-22 … 2026-06-25) on the same machine, same runner and same
> `DB_POOL_MAX=1` setup — opponent code did not change between those dates.

**Swoole (TechEmpower)** — [report](results/swoole/RESULTS-techempower-2026-07-04_002103.md)

![Bootgly vs Swoole — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/swoole/RESULTS-techempower-2026-07-04_002103.chart.throughput.png)

**Hyperf** — [report](results/hyperf/RESULTS-techempower-2026-07-04_002106.md)

![Bootgly vs Hyperf — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/hyperf/RESULTS-techempower-2026-07-04_002106.chart.throughput.png)

![Bootgly vs Hyperf — TechEmpower latency](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/hyperf/RESULTS-techempower-2026-07-04_002106.chart.latency.png)

**ReactPHP** — [report](results/reactphp/RESULTS-techempower-2026-07-04_002108.md)

![Bootgly vs ReactPHP — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/reactphp/RESULTS-techempower-2026-07-04_002108.chart.throughput.png)

**AMPHP** — [report](results/amphp/RESULTS-techempower-2026-07-04_002111.md)

![Bootgly vs AMPHP — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/amphp/RESULTS-techempower-2026-07-04_002111.chart.throughput.png)

**Laravel (nginx / Apache, PHP-FPM)** — [report](results/laravel-fpm/RESULTS-techempower-2026-07-04_002115.md)

![Bootgly vs Laravel FPM — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-fpm/RESULTS-techempower-2026-07-04_002115.chart.throughput.png)

**Laravel (Octane)** — [report](results/laravel-octane/RESULTS-techempower-2026-07-04_002118.md)

![Bootgly vs Laravel Octane — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-octane/RESULTS-techempower-2026-07-04_002118.chart.throughput.png)

**Laravel (OpenLiteSpeed + LSCache)** — [report](results/laravel-ols/RESULTS-techempower-2026-07-04_002121.md)

![Bootgly vs Laravel OLS — TechEmpower throughput](https://github.com/bootgly/bootgly_benchmarks/raw/main/HTTP_Server_CLI/results/laravel-ols/RESULTS-techempower-2026-07-04_002121.chart.throughput.png)

#### 1. Run a sweep — one command

Sweep values on `--server-workers` run every round in one process and — with
[`--results=charts`](#output-format-and-results) — generate the Markdown report
and native SVG charts in the same run, no Python required:

```bash
cd bootgly
export DB_HOST=127.0.0.1 DB_PORT=5432 DB_NAME=bootgly DB_USER=postgres \
       DB_PASS='' DB_SSLMODE=disable DB_POOL_MAX=1
php bootgly test benchmark HTTP_Server_CLI \
   --opponents=bootgly,swoole --runner=tcp_client \
   --connections=514 --duration=10 \
   --server-workers=1..24 --loads=techempower:1,2,3,4,5,6 \
   --results=charts
```

Everything lands below one exclusive
`bootgly/storage/tests/benchmarks/HTTP_Server_CLI/runs/<run-id>/` workspace:
one file in `marks/` per round, optional reports/charts in `reports/`, optional
process channels and statuses in `runners/`, supervised harness logs and
`result.json` in JSON mode, and `manifest.json`.

The same sweep runs **fully inside Docker** — mount a host directory to keep
the artifacts:

```bash
docker run --rm -v "$(pwd)/results:/bootgly/storage/tests/benchmarks" \
   bootgly/bootgly_benchmarks:swoole \
   test benchmark HTTP_Server_CLI --opponents=bootgly,swoole \
   --loads=techempower:1,2 --server-workers=1..24:4 --results=charts
```

#### 2. Legacy PNG tooling (`chart.py`)

The Python `chart.py` renders the same reports as PNGs (the format used by the
published reports above). It consumes the exact same `.bench.marks` files, so
it keeps working on sweep output — one-time setup:

```bash
cd bootgly_benchmarks/scripts
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt   # Python >= 3.10 + matplotlib
```

Then point `--marks` at a glob covering **every** `.marks` file from the sweep:

```bash
cd bootgly_benchmarks/scripts
.venv/bin/python3 chart.py \
   --marks '../../bootgly/storage/tests/benchmarks/HTTP_Server_CLI/runs/<run-id>/marks/*_bench.marks' \
   --out ../HTTP_Server_CLI/results/ \
   --baseline Bootgly
```

#### Tips & gotchas

- **Keep the glob inside one exact run directory.** It must match every marks
  file in that sweep without pulling files from another invocation.
  `chart.py` aborts with *"No config key varies"* when the glob resolves to a
  single file (nothing to put on the X axis).
- Artifact workspaces are safe from cross-invocation overwrites, but runtime
  resources are not isolated. Service-backed cases use fixed ports and their
  startup/cleanup can terminate a process already bound there; database loads
  also seed/use the configured PostgreSQL database. Run service-backed
  benchmarks serially. Even different cases should not overlap for comparable
  measurements because they still contend for host resources.
- **Subplots follow `--loads`.** The chart draws one subplot per load present in the
  marks, so the `--loads` you ran during the sweep decide which subplots appear.
- **X axis is auto-detected** (the config key with the most distinct values). Force
  it with `--x-key server-workers` if several keys vary.
- **Baseline** defaults to the first opponent alphabetically; pass
  `--baseline Bootgly` to pin it (drives the ratio chart and `Δ` columns).
- **Filenames** use the marks `# Config: load-set` key (`techempower` or
  `benchmark`), so the two sets never overwrite each other.

See [`scripts/README.md`](../scripts/README.md) for the full `chart.py` CLI reference.
