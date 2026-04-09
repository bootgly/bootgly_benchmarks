# ⏱️ Benchmark — HTTP Server CLI (Router Performance)

Comprehensive router-level benchmark comparing **Bootgly HTTP Server CLI** against popular PHP HTTP servers.

Unlike simple "Hello, World!" benchmarks, this suite tests **real routing patterns**: static routes, dynamic parameters, nested groups, per-route middleware, and catch-all handlers — the kind of workload a production application actually sees.

---

## 📋 Table of Contents

- [Route Specification](#-route-specification)
- [Benchmark Scenarios](#-benchmark-scenarios)
- [Runners](#-runners)
- [Competitors](#-competitors)
- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Running Benchmarks](#-running-benchmarks)
- [Multi-dimensional Vary](#-multi-dimensional-vary---vary)
- [Contextual Help](#-contextual-help)
- [Understanding Results](#-understanding-results)
- [Adding a New Competitor](#-adding-a-new-competitor)
- [Environment Notes](#-environment-notes)

---

## 🗺️ Route Specification

Every competitor implements the **same** route set for fair comparison.

### Static Routes (100)

The first 10 are named routes:

| Path | Response Body |
|------|--------------|
| `/` | `Home` |
| `/about` | `About` |
| `/contact` | `Contact` |
| `/blog` | `Blog` |
| `/pricing` | `Pricing` |
| `/docs` | `Docs` |
| `/faq` | `FAQ` |
| `/terms` | `Terms` |
| `/privacy` | `Privacy` |
| `/status` | `Status` |

Routes 11–100 follow the pattern `/static/N` → `Static N` (e.g. `/static/11` → `Static 11`).

### Dynamic Routes (100)

The first 10 use distinct path prefixes:

| Pattern | Example Path | Response Body |
|---------|-------------|--------------|
| `/user/:id` | `/user/42` | `User: 42` |
| `/post/:slug` | `/post/hello` | `Post: hello` |
| `/api/v1/:resource` | `/api/v1/items` | `API: items` |
| `/category/:name` | `/category/tech` | `Category: tech` |
| `/tag/:label` | `/tag/php` | `Tag: php` |
| `/product/:sku` | `/product/ABC-1` | `Product: ABC-1` |
| `/order/:code` | `/order/ORD-99` | `Order: ORD-99` |
| `/invoice/:number` | `/invoice/INV-1` | `Invoice: INV-1` |
| `/review/:rid` | `/review/R-50` | `Review: R-50` |
| `/comment/:cid` | `/comment/C-10` | `Comment: C-10` |

Routes 11–100 follow the pattern `/dN/:param` → `D{N}: {param}` (e.g. `/d11/foo` → `D11: foo`).

### Nested Routes (6) — 2 groups

| Path | Response Body |
|------|--------------|
| `/admin/dashboard` | `Admin Dashboard` |
| `/admin/settings` | `Admin Settings` |
| `/admin/users` | `Admin Users` |
| `/account/profile` | `Account Profile` |
| `/account/billing` | `Account Billing` |
| `/account/security` | `Account Security` |

### Middleware Routes (3) — Bootgly only *

| Path | Response Body |
|------|--------------|
| `/protected/dashboard` | `Protected Dashboard` |
| `/protected/settings` | `Protected Settings` |
| `/protected/profile` | `Protected Profile` |

> \* Only Bootgly applies **real per-route middleware** (`RequestId`). Other competitors implement these as simple path matching (no middleware processing) for fair comparison in mixed scenarios.

### Catch-all

| Pattern | Response |
|---------|---------|
| `/*` (any unmatched path) | `404 Not Found` |

---

## 🎯 Benchmark Scenarios

Each scenario defines a request distribution. There are two formats — **Lua** scripts (for the WRK runner) and **PHP** scripts (for the TCP_Client runner) — with identical request patterns.

### Scenarios (12)

| # | File | Label | Group | Description |
|---|------|-------|-------|-------------|
| 1 | `1.1.1-static_single` | 1 static route | Static | Hits `/` on every request (best-case) |
| 2 | `1.1.2-static_10` | 10 static routes | Static | Round-robins all 10 named static routes |
| 3 | `1.1.3-static_100` | 100 static routes | Static | Round-robins all 100 static routes |
| 4 | `1.2.1-dynamic_1` | 1 dynamic route | Dynamic | `/user/:id` |
| 5 | `1.2.2-dynamic_10` | 10 dynamic routes | Dynamic | All 10 named dynamic routes with varying params |
| 6 | `1.2.3-dynamic_100` | 100 dynamic routes | Dynamic | All 100 dynamic routes with varying params |
| 7 | `1.3.1-catch_all` | Catch-all 404 | Catch-all | All requests hit non-existent paths |
| 8 | `2.1.1-nested_6` | 6 nested routes | Nested | Admin + Account group routes |
| 9 | `3.1.1-middleware_3` | 3 middleware routes | Middleware | Protected routes (**Bootgly only**) |
| 10 | `z.1.1-mixed_8` | Mixed 8 | Mixed | 5 static + 3 dynamic |
| 11 | `z.1.2-mixed_20` | Mixed 20 | Mixed | 10 static + 10 dynamic |
| 12 | `z.1.3-full_mix` | Full mix | Mixed | static + dynamic + nested + middleware + 404 |

File extensions: `.lua` (in `scenarios/`) for WRK runner, `.php` (in `scenarios/php/`) for TCP_Client runner.

### `@competitors` Tag

Each scenario has a `@competitors:` header that controls which competitors run it:
- `all` — every competitor runs the scenario
- `bootgly` — only Bootgly runs it (e.g., middleware scenario)

Competitors not listed are **automatically skipped** during the benchmark.

---

## 🔧 Runners

The HTTP_Server_CLI case supports two runners. The runner is selected via `--runner=NAME` (default: `tcp_client`).

### TCP_Client (default)

Bootgly's built-in HTTP load generator. Uses PHP scenarios from `scenarios/php/`. Supports multi-worker forking and HTTP pipelining.

| Option | Default | Description |
|--------|---------|-------------|
| `--client-workers=N` | auto | Number of client worker processes |
| `--connections=N` | `514` | Number of TCP connections |
| `--duration=N` | `10` | Benchmark duration in seconds |
| `--pipeline=N` | `1` | HTTP pipelining factor |

### WRK

Wraps the external [wrk](https://github.com/wg/wrk) HTTP benchmarking tool. Uses Lua scenarios from `scenarios/`.

| Option | Default | Description |
|--------|---------|-------------|
| `--threads=N` | `10` | Number of wrk threads |

> **Note:** `wrk` must be installed separately (see [Prerequisites](#-prerequisites)).

---

## 🏁 Competitors

| Server | Language / Runtime | Mode |
|--------|-------------------|------|
| **Bootgly** HTTP Server CLI | PHP (event-loop) | Baseline |
| **Workerman** v5 | PHP (event-loop) | Routes |
| **Swoole** (Base mode) | PHP (C extension) | Reactor per worker |
| **Swoole** (Process mode) | PHP (C extension) | Master + workers |
| **Swoole** (Coroutine mode) | PHP (C extension) | Coroutine + fork |
| **RoadRunner** | Go + PHP (goridge) | PSR-7 worker |
| **FrankenPHP** | Go + PHP (worker mode) | Caddy-based |
| **Hyperf** | PHP (Swoole framework) | Full-stack coroutine |

All competitors use `nproc / 2` workers for fair CPU distribution.

---

## ✅ Prerequisites

| Dependency | Purpose | Required |
|-----------|---------|----------|
| **PHP** ≥ 8.4 | Server runtime | ✅ |
| **Composer** | PHP dependency management | ✅ |
| **lsof** | Port detection | ✅ |
| **curl** | Server readiness check | ✅ |
| **nproc** | CPU count detection | ✅ |
| **wrk** | HTTP benchmarking tool | Only for `--runner=wrk` |
| **Swoole extension** | Swoole / Hyperf benchmarks | Optional |
| **FrankenPHP binary** | FrankenPHP benchmarks | Optional |

---

## 🔧 Installation

### 1. Clone repositories (side by side)

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

Expected layout:

```
parent/
├── bootgly/
└── bootgly_benchmarks/
```

### 2. Install competitor dependencies

```bash
cd bootgly_benchmarks/HTTP_Server_CLI/artifacts
```

#### RoadRunner

```bash
cd roadrunner && composer install && php ./vendor/bin/rr get-binary && cd ..
```

#### Workerman

```bash
cd workerman && composer install && cd ..
```

#### Hyperf

```bash
cd hyperf && composer install && cd ..
```

> **Note:** Hyperf requires the Swoole extension with `swoole.use_shortname=Off` in your `php.ini`.

### 3. Install wrk (only for `--runner=wrk`)

```bash
# Debian/Ubuntu
sudo apt-get install wrk

# macOS
brew install wrk

# Build from source
git clone https://github.com/wg/wrk.git
cd wrk && make -j$(nproc) && sudo cp wrk /usr/local/bin/
```

### 4. Install Swoole (optional)

```bash
pecl install swoole
```

Add `extension=swoole.so` to your `php.ini`.

### 5. Install FrankenPHP (optional)

```bash
curl -fsSL https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
   -o /usr/local/bin/frankenphp
chmod +x /usr/local/bin/frankenphp
```

See: https://frankenphp.dev/docs/install

---

## 🚀 Running Benchmarks

All commands are run from the **bootgly** directory:

```bash
cd /path/to/bootgly
```

### Basic usage

```bash
# All competitors, all scenarios (TCP_Client runner, default)
./bootgly test benchmark HTTP_Server_CLI

# Bootgly only (baseline)
./bootgly test benchmark HTTP_Server_CLI --competitors=bootgly

# Bootgly vs Workerman
./bootgly test benchmark HTTP_Server_CLI --competitors=bootgly,workerman

# Bootgly vs multiple competitors
./bootgly test benchmark HTTP_Server_CLI --competitors=bootgly,workerman,swoole-base,roadrunner
```

### Selecting a runner

```bash
# Use wrk runner (requires wrk installed)
./bootgly test benchmark HTTP_Server_CLI --runner=wrk

# Use TCP_Client runner (default, no external dependency)
./bootgly test benchmark HTTP_Server_CLI --runner=tcp_client
```

### Filtering scenarios

```bash
# Run only scenario 1 (static single) and 10 (mixed 8)
./bootgly test benchmark HTTP_Server_CLI --competitors=bootgly,workerman --scenarios=1,10

# Run only the 100-route scenarios (3 and 6)
./bootgly test benchmark HTTP_Server_CLI --scenarios=3,6
```

### Runner-specific options

```bash
# TCP_Client: custom connections and duration
./bootgly test benchmark HTTP_Server_CLI --connections=256 --duration=15

# TCP_Client: with pipelining and explicit client workers
./bootgly test benchmark HTTP_Server_CLI --pipeline=4 --client-workers=8

# WRK: custom threads
./bootgly test benchmark HTTP_Server_CLI --runner=wrk --threads=8
```

### Case-specific options

```bash
# Set server workers explicitly
./bootgly test benchmark HTTP_Server_CLI --server-workers=4
```

### Global options

| Option | Description |
|--------|-------------|
| `--runner=NAME` | Select runner (`tcp_client`, `wrk`) |
| `--competitors=NAME,...` | Filter competitors by name |
| `--scenarios=N,...` | Filter scenarios by 1-based index |
| `--vary=KEY:VALUE,...` | Multi-dimensional benchmarking (see below) |

### Available competitor names

| Name | Description |
|------|-------------|
| `bootgly` | Bootgly HTTP Server CLI (always baseline) |
| `workerman` | Workerman v5 |
| `swoole-base` | Swoole SWOOLE_BASE mode |
| `swoole-process` | Swoole SWOOLE_PROCESS mode |
| `swoole-coroutine` | Swoole Coroutine mode |
| `roadrunner` | RoadRunner (Go + PHP) |
| `frankenphp` | FrankenPHP worker mode |
| `hyperf` | Hyperf (Swoole framework) |

---

## 📐 Multi-dimensional Vary (`--vary`)

The `--vary` option runs the benchmark across a **cartesian product** of parameter values, producing one round per combination.

### Syntax

```bash
--vary=key1:value1,key2:value2,...
```

### Available dimensions

| Key | Runner | Description |
|-----|--------|-------------|
| `server-workers` | Both | Number of server worker processes |
| `connections` | TCP_Client | Number of TCP connections |
| `client-workers` | TCP_Client | Number of client worker processes |
| `threads` | WRK | Number of wrk threads |

### Examples

```bash
# 2D: vary server workers and connections (TCP_Client)
./bootgly test benchmark HTTP_Server_CLI \
   --vary=server-workers:4,connections:256

# 3D: full cartesian product (TCP_Client)
./bootgly test benchmark HTTP_Server_CLI \
   --vary=server-workers:4,connections:256,client-workers:8

# 2D: vary server workers and threads (WRK)
./bootgly test benchmark HTTP_Server_CLI --runner=wrk \
   --vary=server-workers:4,threads:8
```

Round headers show only the dimensions that are actually varying (e.g. `4sw/256c` instead of `4sw/256c/0cw`).

---

## 💡 Contextual Help

The benchmark CLI provides a two-tier help system:

### Tier 1 — List available cases

```bash
./bootgly test benchmark --help
```

Shows all discovered benchmark cases.

### Tier 2 — Case-specific options

```bash
./bootgly test benchmark HTTP_Server_CLI --help
```

Shows:
- Case configuration (from `@.php`)
- Runner-specific options (from the selected runner's `options()` method)
- Case-local options (from `options.php`, e.g. `--server-workers`)

---

## 📊 Understanding Results

### Terminal output

The runner prints a **pairwise comparison table** for each competitor against Bootgly (baseline):

```
  Bootgly vs Workerman

  │ Scenario         │ Bootgly    │ Workerman  │   Diff │ B. Latency │ C. Latency │
  │ 1 static route   │    210,000 │    195,000 │  +7.7% │     4.75ms │     5.12ms │
  │ 10 static routes │    185,000 │    172,000 │  +7.6% │     5.40ms │     5.80ms │
  ...
```

- **Diff** = `((Bootgly - Competitor) / Competitor) × 100` — positive means Bootgly is faster
- **Latency** = average response time

### Saved results

Results are saved to:
```
bootgly/workdata/tests/benchmarks/HTTP_Server_CLI/<timestamp>_bench.marks
```

Plain text format, easy to diff or archive.

---

## 🔌 Adding a New Competitor

### 1. Create the competitor script

Create `competitors/myserver.php` — this script starts the HTTP server and blocks until killed:

```php
<?php
// Start your server on $port with $workers processes
// The runner will call this script as a subprocess
// and kill it when done.
```

See existing scripts in `competitors/` for the exact pattern (start server, listen on `getenv('PORT')`, use `getenv('WORKERS')` for worker count).

### 2. Create the server artifact

Create `artifacts/myserver/` with the full route implementation:
- 100 static routes + 100 dynamic routes + 6 nested + 3 middleware + catch-all
- See any existing artifact directory for the route specification

### 3. Register in `@.php`

Add a `Competitor` entry to `@.php`:

```php
$Runner->add(new Competitor(
   name: 'MyServer',
   version: fn () => 'v1.0.0',
   script: __DIR__ . '/competitors/myserver.php',
));
```

The `name` parameter is used as the CLI filter value (lowercased): `--competitors=myserver`.

### 4. Run

```bash
./bootgly test benchmark HTTP_Server_CLI --competitors=bootgly,myserver
```

---

## ⚠️ Environment Notes

- **CPU balance**: Both the load generator and the server share CPU. The default uses `nproc / 2` workers to leave cores for the load generator.
- **TCP_Client runner**: Uses Bootgly's built-in TCP client — no external tooling required. Scenarios are PHP scripts under `scenarios/php/`.
- **WRK runner**: Requires the `wrk` binary installed. Scenarios are Lua scripts under `scenarios/`.
- **WSL2**: `lsof` may not detect Go-based binaries (FrankenPHP, RoadRunner). The runner uses `curl` polling as fallback.
- **Localhost loopback**: All tests run on `127.0.0.1` — network latency is not a factor.
- **Swoole extension**: Must be compiled with CLI support. Verify with `php -m | grep swoole`.
- **Hyperf**: Requires Swoole with `swoole.use_shortname=Off`. Add to `php.ini` before running.
- **Result variance**: Results vary by hardware, OS, PHP version, and system load. Always compare on the **same machine, same session**.
- **Warmup**: A warmup phase runs before each benchmark to stabilize JIT, TCP buffers, and worker pools.
