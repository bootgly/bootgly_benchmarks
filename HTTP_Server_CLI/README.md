# ⏱️ Benchmark — HTTP Server CLI (Router Performance)

Comprehensive router-level benchmark comparing **Bootgly HTTP Server CLI** against popular PHP HTTP servers.

Unlike simple "Hello, World!" benchmarks, this suite tests **real routing patterns**: static routes, dynamic parameters, nested groups, per-route middleware, and catch-all handlers — the kind of workload a production application actually sees.

---

## 📋 Table of Contents

- [Route Specification](#-route-specification)
- [Benchmark Scenarios](#-benchmark-scenarios)
- [Competitors](#-competitors)
- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Running Benchmarks](#-running-benchmarks)
- [Understanding Results](#-understanding-results)
- [Adding a New Competitor](#-adding-a-new-competitor)
- [Environment Notes](#-environment-notes)

---

## 🗺️ Route Specification

Every competitor implements the **same** route set for fair comparison.

### Static Routes (10)

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

### Dynamic Routes (10)

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

Each scenario is a [wrk](https://github.com/wg/wrk) Lua script that defines the request distribution.

| # | File | Label | Group | Description |
|---|------|-------|-------|-------------|
| 1 | `1.1.1-static_single.lua` | 1 static route | Static | Hits `/` on every request (best-case) |
| 2 | `1.1.2-static_10.lua` | 10 static routes | Static | Round-robins all 10 static routes |
| 3 | `1.2.1-dynamic_3.lua` | 3 dynamic routes | Dynamic | `/user/:id`, `/post/:slug`, `/api/v1/:resource` |
| 4 | `1.2.2-dynamic_10.lua` | 10 dynamic routes | Dynamic | All 10 dynamic routes with varying params |
| 5 | `1.3.1-catch_all.lua` | Catch-all 404 | Catch-all | All requests hit non-existent paths |
| 6 | `2.1.1-nested_6.lua` | 6 nested routes | Nested | Admin + Account group routes |
| 7 | `3.1.1-middleware_3.lua` | 3 middleware routes | Middleware | Protected routes (**Bootgly only**) |
| 8 | `z.1.1-mixed_8.lua` | Mixed 8 | Mixed | 5 static + 3 dynamic |
| 9 | `z.1.2-mixed_20.lua` | Mixed 20 | Mixed | 10 static + 10 dynamic |
| 10 | `z.1.3-full_mix.lua` | Full mix | Mixed | static + dynamic + nested + middleware + 404 |

### `@competitors` Tag

Each scenario has a `-- @competitors:` header that controls which competitors run it:
- `all` — every competitor runs the scenario
- `bootgly` — only Bootgly runs it (e.g., middleware scenario)

Competitors not listed are **automatically skipped** during the benchmark.

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
| **wrk** | HTTP benchmarking tool | ✅ |
| **lsof** | Port detection | ✅ |
| **curl** | Server readiness check | ✅ |
| **nproc** | CPU count detection | ✅ |
| **Swoole extension** | Swoole / Hyperf benchmarks | Optional |
| **FrankenPHP binary** | FrankenPHP benchmarks | Optional |

---

## 🔧 Installation

### Automated (recommended)

```bash
# Install all dependencies
bash install.sh

# Or install specific competitors
bash install.sh --roadrunner --workerman
bash install.sh --wrk
```

### Manual step-by-step

#### 1. Clone repositories

```bash
# Clone Bootgly (the main repo with the benchmark runner)
git clone --recursive https://github.com/bootgly/bootgly.git
cd bootgly
```

> The `--recursive` flag pulls the `bootgly_benchmarks` submodule automatically.
> If you already have the repo: `git submodule update --init --recursive`

#### 2. Install wrk

```bash
# Debian/Ubuntu
sudo apt-get install wrk

# macOS
brew install wrk

# Build from source (if unavailable in package manager)
git clone https://github.com/wg/wrk.git
cd wrk && make -j$(nproc) && sudo cp wrk /usr/local/bin/
```

#### 3. Install RoadRunner

```bash
cd scripts/benchmarks/bootgly_benchmarks/HTTP_Server_CLI/artifacts/roadrunner
composer install
php ./vendor/bin/rr get-binary
cd -
```

#### 4. Install Workerman

```bash
cd scripts/benchmarks/bootgly_benchmarks/HTTP_Server_CLI/artifacts/workerman
composer install
cd -
```

#### 5. Install Hyperf

```bash
cd scripts/benchmarks/HTTP_Server_CLI/artifacts/hyperf
composer install
cd -
```

> **Note:** Hyperf requires the Swoole extension with `swoole.use_shortname=Off` in your `php.ini`.

#### 6. Install Swoole (optional)

```bash
# Via PECL
pecl install swoole

# Or build from source
git clone https://github.com/swoole/swoole-src.git
cd swoole-src && phpize && ./configure && make -j$(nproc) && sudo make install
```

Add `extension=swoole.so` to your `php.ini`.

#### 7. Install FrankenPHP (optional)

```bash
# Download static binary
curl -fsSL https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
   -o /usr/local/bin/frankenphp
chmod +x /usr/local/bin/frankenphp
```

See: https://frankenphp.dev/docs/install

---

## 🚀 Running Benchmarks

The benchmark runner lives in the **bootgly** repository:

```bash
cd /path/to/bootgly
```

### Basic usage

```bash
# Bootgly only (baseline)
./scripts/benchmark HTTP_Server_CLI --competitors=bootgly

# Bootgly vs Workerman
./scripts/benchmark HTTP_Server_CLI --competitors=bootgly,workerman

# Bootgly vs multiple competitors
./scripts/benchmark HTTP_Server_CLI --competitors=bootgly,workerman,swoole-base,roadrunner
```

### Specific scenarios

```bash
# Run only scenario 1 (static single) and 8 (mixed 8)
./scripts/benchmark benchmark HTTP_Server_CLI --competitors=bootgly,workerman --scenarios=1,8

# Run only dynamic routes scenarios
./scripts/benchmark benchmark HTTP_Server_CLI --competitors=bootgly,workerman --scenarios=3,4
```

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

### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PORT` | `8082` | Server listen port |
| `WRK_THREADS` | `10` | wrk threads |
| `WRK_CONNECTIONS` | `514` | wrk connections |
| `WRK_DURATION` | `10s` | wrk test duration |
| `WARMUP_DURATION` | `2s` | Warmup duration before each benchmark |
| `WARMUP_THREADS` | `2` | Warmup threads |
| `WARMUP_CONNECTIONS` | `64` | Warmup connections |
| `READY_TIMEOUT` | `10` | Server start timeout (seconds) |

### Example

```bash
PORT=8080 WRK_THREADS=8 WRK_DURATION=15s \
   ./scripts/benchmark benchmark HTTP_Server_CLI --competitors=bootgly,workerman
```

### List available scenarios

```bash
./scripts/benchmark benchmark HTTP_Server_CLI --help
```

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

1. **Copy the template**:
   ```bash
   cp competitors/Template.example.sh competitors/myserver/myserver.sh
   ```

2. **Create a route handler** in `artifacts/myserver/` with the full route set
   (see any existing artifact for the pattern: 10 static + 10 dynamic + 6 nested + 3 middleware + catch-all)

3. **Edit the driver** — implement `driver_start()` and `driver_stop()`, set `DRIVER_NAME`, `DRIVER_VERSION`, `DRIVER_WORKERS`

4. **Run**:
   ```bash
   ./scripts/benchmark benchmark HTTP_Server_CLI --competitors=bootgly,myserver
   ```

### Driver interface

```bash
# Required exports
DRIVER_NAME="MyServer"
DRIVER_VERSION="1.0.0"
DRIVER_WORKERS=$(( $(nproc) / 2 ))

# Required functions
driver_start ()   # Start server, block until ready
driver_stop ()    # Stop server, release port

# Available helpers (from benchmark runner)
wait_for_server   # Polls $PORT until server is listening
kill_port         # Force-kills any process on $PORT
```

---

## ⚠️ Environment Notes

- **CPU balance**: Both `wrk` and the server share CPU. The default uses `nproc / 2` workers to leave cores for `wrk`.
- **WSL2**: `lsof` may not detect Go-based binaries (FrankenPHP, RoadRunner). FrankenPHP uses `curl` polling instead.
- **Localhost loopback**: All tests run on `127.0.0.1` — network latency is not a factor.
- **Swoole extension**: Must be compiled with CLI support. Verify with `php -m | grep swoole`.
- **Hyperf**: Requires Swoole with `swoole.use_shortname=Off`. Add to `php.ini` before running.
- **Result variance**: Results vary by hardware, OS, PHP version, and system load. Always compare on the **same machine, same session**.
- **Warmup**: A 2-second warmup phase runs before each benchmark to stabilize JIT, TCP buffers, and worker pools.
