# HTTP_Server_CLI Benchmark Results — Router

Pure HTTP routing, no database. Scenario set: `router`.

## Command

Run from the `bootgly` repository root.

Peak-hunt — sweep `--server-workers` to find each competitor's peak (scenario
`1` = `static_single`):

```bash
for sw in 8 13 16 18 20 24; do
   php bootgly test benchmark HTTP_Server_CLI \
      --competitors=bootgly,swoole-base \
      --runner=tcp_client \
      --connections=514 --duration=10 \
      --server-workers="$sw" \
      --scenarios=1
done
```

Final subset — each competitor at its own peak (Bootgly `16`, Swoole `13`):

```bash
php bootgly test benchmark HTTP_Server_CLI --competitors=bootgly \
   --runner=tcp_client --connections=514 --duration=10 \
   --server-workers=16 --scenarios=1,3,6,11,12

php bootgly test benchmark HTTP_Server_CLI --competitors=swoole-base \
   --runner=tcp_client --connections=514 --duration=10 \
   --server-workers=13 --scenarios=1,3,6,11,12
```

Scenario numbers: `1` static_single, `3` static_100, `6` dynamic_100,
`11` mixed_20, `12` full_mix. Client workers default to auto (`nproc / 2`);
override with `--client-workers=N`.

## Environment

- WSL2, Ryzen 9 3900X (12 cores / 24 threads), PHP 8.4.21
- Bootgly v0.15.0-beta vs Swoole (Base) v6.2.0 (`SWOOLE_BASE` mode)
- Runner: `tcp_client` (Bootgly TCP_Client_CLI load generator)
- `connections=514`, `duration=10s`, client workers auto (`nproc / 2` = 12)

## Methodology — peak per competitor

Each competitor reaches max throughput at a different `--server-workers` count.
Reporting both at one fixed count favors whichever count happens to suit one of
them. So each competitor is first swept to find its own peak, then the scenario
subset is run at that peak.

Server and client run on the same 12-core / 24-thread machine. Peak throughput
occurs when server + client processes saturate the cores without heavy
oversubscription.

### Peak-hunt — scenario `1 static route`

| server-workers | Bootgly req/s | Swoole (Base) req/s |
|----------------|---------------|---------------------|
| 8  | 385,942 | 337,346 |
| 13 | 596,489 | **630,341** ← Swoole peak |
| 16 | **649,317** ← Bootgly peak | 624,918 |
| 18 | 596,127 | 584,338 |
| 20 | 566,652 | 561,568 |
| 24 | 541,531 | 519,367 |

- **Bootgly peak: 16 server workers**
- **Swoole (Base) peak: 13 server workers**

Both degrade past 16–18 workers — oversubscription: 16S + 12C = 28 processes on
24 threads.

## Results — each competitor at its own peak

Bootgly @ 16 server workers vs Swoole (Base) @ 13 server workers.

| Scenario | Bootgly | Swoole (Base) | Result |
|----------|---------|---------------|--------|
| 1 static route                 | 604,969 req/s | 612,038 req/s | Swoole +1.2% |
| 100 static routes              | 615,968 req/s | 566,748 req/s | Bootgly +8.7% |
| 100 dynamic routes             | 532,115 req/s | 578,676 req/s | Swoole +8.8% |
| Mixed (10 static + 10 dynamic) | 563,275 req/s | 588,398 req/s | Swoole +4.5% |
| Full mix (all types)           | 520,995 req/s | 583,582 req/s | Swoole +12.0% |

## Reading the result

Roughly even, slight Swoole edge on dynamic-heavy scenarios.

- **Static routes** — Bootgly's route table is a direct hash lookup. Wins
  `100 static routes` (+8.7%), ties `1 static route`.
- **Dynamic / mixed / full** — Swoole wins (+4.5% to +12%). Dynamic routes need
  path segmentation + parameter extraction per request; Swoole's C reactor and
  C-level request handling absorb that better than Bootgly's PHP-userland
  router/encoder.
- Pure HTTP (no DB) is a C-vs-PHP race. Close, no blowout either direction.

The router result is the baseline for reading `RESULTS.database.md`: the
database scenario `/fortunes` carries a large pure-HTTP component, so it tracks
this benchmark more than it tracks raw database throughput.
