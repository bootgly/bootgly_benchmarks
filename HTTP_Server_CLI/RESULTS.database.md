# HTTP_Server_CLI Benchmark Results — Database

TechEmpower-style database scenarios. Scenario set: `database`.

## Command

Run from the `bootgly` repository root. PostgreSQL must be running with the
TechEmpower schema seeded (`artifacts/@postgresql/techempower.sql`).

Peak-hunt — sweep `--server-workers` to find each competitor's peak (scenario
`1` = `/db`):

```bash
for sw in 13 16 18 24; do
   env BOOTGLY_HTTP_SERVER_CLI_ROUTER=database \
       BOOTGLY_HTTP_SERVER_CLI_SCENARIOS=database \
       DB_HOST=127.0.0.1 \
       DB_PORT=5432 \
       DB_NAME=bootgly \
       DB_USER=postgres \
       DB_PASS='' \
       DB_SSLMODE=disable \
       DB_POOL_MAX=3 \
   php bootgly test benchmark HTTP_Server_CLI \
      --competitors=bootgly,swoole-database \
      --runner=tcp_client \
      --connections=514 --duration=10 \
      --server-workers="$sw" \
      --scenarios=1
done
```

Final — both competitors at their shared peak (`24` server workers):

```bash
env BOOTGLY_HTTP_SERVER_CLI_ROUTER=database \
    BOOTGLY_HTTP_SERVER_CLI_SCENARIOS=database \
    DB_HOST=127.0.0.1 \
    DB_PORT=5432 \
    DB_NAME=bootgly \
    DB_USER=postgres \
    DB_PASS='' \
    DB_SSLMODE=disable \
    DB_POOL_MAX=3 \
php bootgly test benchmark HTTP_Server_CLI \
   --competitors=bootgly,swoole-database \
   --runner=tcp_client \
   --connections=514 --duration=10 \
   --server-workers=24 \
   --scenarios=1,2,3,4
```

Scenario numbers: `1` /db, `2` /query, `3` /fortunes, `4` /updates. Client
workers default to auto (`nproc / 2`); override with `--client-workers=N`.

## Environment

- WSL2, Ryzen 9 3900X (12 cores / 24 threads), PHP 8.4.21
- PostgreSQL local, `pdo_pgsql` enabled, default `max_connections=100`
- Bootgly v0.15.0-beta vs Swoole Database v6.2.0 (`SWOOLE_PROCESS` mode, PDO pool)
- Runner: `tcp_client` (Bootgly TCP_Client_CLI load generator)
- `connections=514`, `duration=10s`, client workers auto (`nproc / 2` = 12)
- `DB_POOL_MAX=3`

## Scenarios

| Scenario | Route | Work |
|----------|-------|------|
| Database single query    | `/db`                | Fetch one random `World` row |
| Database multiple queries| `/query?queries=20`  | Fetch 20 random `World` rows |
| Database fortunes        | `/fortunes`          | Fetch fortunes, append one row, sort, escape, render HTML |
| Database updates         | `/updates?queries=20`| Fetch 20 random `World` rows and update them |

## Methodology — peak per competitor

Each competitor reaches max throughput at a different `--server-workers` count,
so each is swept to its own peak first, then the scenario subset is run there.

Database scenarios spend most of each request waiting on PostgreSQL I/O, so they
keep scaling with server workers further than the router scenarios — until
PostgreSQL connection pressure caps it. `DB_POOL_MAX=3` means N workers open up
to N×3 connections; default `max_connections=100` is reached near 32 workers.

### Peak-hunt — scenario `/db`

| server-workers | PG conns (≤ N×3) | Bootgly req/s | Swoole Database req/s |
|----------------|------------------|---------------|-----------------------|
| 13 | ≤39 | 55,453 | 23,134 |
| 18 | ≤54 | 64,938 | 53,777 |
| 24 | ≤72 | **69,770** | **60,772** |
| 32 | ≤96 | 68,948 | 59,398 |

Both peak at **24 server workers**. 32 dips slightly — 96 connections crowd
`max_connections=100`.

## Results — both at 24 server workers

| Scenario | Bootgly | Swoole Database | Result |
|----------|---------|-----------------|--------|
| Database single query (`/db`)              | 69,240 req/s | 60,607 req/s | Bootgly +14.2% |
| Database multiple queries (`/query?queries=20`) | 14,800 req/s | 11,313 req/s | Bootgly +30.8% |
| Database fortunes (`/fortunes`)            | 58,688 req/s | 62,136 req/s | Swoole +5.9% |
| Database updates (`/updates?queries=20`)   | 6,621 req/s  | 3,461 req/s  | Bootgly +91.3% |

`/query` and `/updates` were re-measured after the pipelining work in
`OPTIMIZATIONS.md` finding #1 (router-level batching, then true per-connection
pipelining in the pool/driver). Progression at 24 workers:

| Scenario | Pre-optimization | + router batching | + pool/driver pipelining |
|----------|------------------|-------------------|--------------------------|
| `/query`   | 11,827 | 15,378 | 14,800 |
| `/updates` |  5,011 |  5,414 |  6,621 |

Per-connection pipelining helps the PostgreSQL-wall-bound scenario (`/updates`,
+22% over router batching) but is flat for `/query`: at 24 workers `/query` is
PHP-CPU-bound on result-set decoding, so cutting round trips does not raise
throughput. `/db` is unchanged (single query, never pipelined).

## Reading the result

- **`/db`, `/query`, `/updates`** — Bootgly wins. Native non-blocking PostgreSQL
  wire-protocol driver: pipelined extended query (Parse + Bind + Describe +
  Execute + Sync in a single flush) plus a server-side prepared-statement cache.
  Swoole goes through PDO / libpq and re-prepares per request. `/updates`
  (+47.1%) gains most: write path, fewer concurrent queries, framework overhead
  stays visible.
- **`/fortunes`** — Swoole wins (+5.9%). `/fortunes` is the database scenario
  with the *smallest* DB component (one query, ~12 rows) and the *largest*
  CPU/HTTP component (HTML render: append, `asort`, `htmlspecialchars`, string
  build, larger body). Its profile is closer to the pure-HTTP router benchmark —
  where Swoole's C core also leads on dynamic/heavy responses (see
  `RESULTS.router.md`). Bootgly's DB-driver edge is too small here to offset the
  heavier PHP-userland row decode (12 text rows) + render + larger body.

`/fortunes` losing is therefore not a database result — it is a pure-HTTP
result wearing a database route.

## Low-worker behavior

At low server-worker counts PostgreSQL is far from saturated and the bottleneck
is per-request framework overhead. Bootgly's lean DB path then leads by a wide
margin — see peak-hunt `/db` at 13 workers: Bootgly 55,453 vs Swoole 23,134
(+140%). The gap collapses toward the 24-worker peak because PostgreSQL becomes
the shared ceiling for both competitors.
