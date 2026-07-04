# ⏱️ Benchmark — WS Server CLI

WebSocket server benchmark for **Bootgly WS Server CLI**, driven by the native
**Bootgly WS Client CLI** as the load generator. It boots `WS_Server_CLI` and
opens many **persistent** WebSocket connections, measuring three things the HTTP
and TCP cases cannot: steady-state message throughput, server-side broadcast
fan-out, and upgrade-handshake rate.

Because the load generator **is** `WS_Client_CLI`, a single run exercises both
sides of Bootgly's WebSocket stack (RFC 6455 / RFC 7692) end to end.

---

## 📋 Table of Contents

- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Loads](#-loads)
- [Runners](#-runners)
- [Opponents](#-opponents)
- [Configuration](#-configuration)
- [Running Benchmarks](#-running-benchmarks)
- [Environment Notes](#-environment-notes)
- [Results](#-results)

---

## ✅ Prerequisites

| Dependency | Purpose | Required |
|-----------|---------|----------|
| **PHP** ≥ 8.4 | Server + client runtime | ✅ |
| **lsof** / **fuser** | Port detection | ✅ |
| **nproc** | CPU count detection | ✅ |

No external opponent dependencies — the only opponent is Bootgly itself.

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

Nothing else to install — the Bootgly opponent boots through the
`bootgly project Benchmark/WS_Server_CLI` command.

---

## 🎯 Loads

A *load* is one PHP file under `loads/<set>/` describing a scenario for the WS
client generator. Each load declares a **`mode`** the worker drives. This case
ships **three load sets**, and each set fixes a distinct **server mode** and
**metric** — so you pass exactly one set per run:

| Set | # | File | Label | Mode | Server mode | Metric |
|-----|---|------|-------|------|-------------|--------|
| `echo` | 1 | `loads/echo/1.1.1-echo_small.php` | Echo 32B | echo | echo | msg/s |
| `echo` | 2 | `loads/echo/1.2.1-echo_large.php` | Echo 4KB | echo | echo | msg/s |
| `echo` | 3 | `loads/echo/1.3.1-echo_binary.php` | Echo 32B (binary) | echo | echo | msg/s |
| `echo` | 4 | `loads/echo/1.4.1-echo_pipelined.php` | Echo 32B (pipelined x16) | echo | echo | msg/s |
| `broadcast` | 1 | `loads/broadcast/2.1.1-broadcast.php` | Broadcast fan-out | broadcast | broadcast | msg/s |
| `connect` | 1 | `loads/connect/3.1.1-connect_rate.php` | Connect rate | connect | echo | conn/s |

**Scenarios:**

- **echo** — each connection sends a frame; on the server's echo it counts and
  immediately resends (1 message in flight per connection). Measures raw framing
  throughput, the closest WebSocket analogue to the TCP/UDP echo cases. Three
  variants isolate cost / regime:
  - **Echo 32B** — text frame; the server validates UTF-8.
  - **Echo 32B (binary)** — binary frame; skips UTF-8 validation (~10–13% faster).
  - **Echo 32B (pipelined x16)** — 16 frames in flight per connection (HTTP
    plaintext's pipelining depth). Shifts from round-trip-latency-bound to
    frame-processing-bound — the regime where HTTP plaintext peaks (breaks 1M
    msg/s here); latency becomes queue-inclusive.
- **broadcast** — every connection joins one server channel; two "ping-pong"
  senders keep the fan-out flowing while **every** connection counts the frames
  it receives. Measures server-side broadcast (`Session::broadcast()`) delivery.
- **connect** — open → upgrade handshake → close, repeated in batches until the
  duration elapses. Measures handshake throughput; reported in the `msg/s` column
  as **completed handshakes per second** (conn/s).

The server's behavior is selected per set via `BENCH_WS_MODE` (the opponent
script derives it from the active load set): `echo` and `connect` run against the
echo server; `broadcast` runs against the fan-out server.

---

## 🔧 Runners

Uses the **WS_Raw** runner (`runners/WS_Raw.php`), which spawns a standalone
worker process per load. The worker uses `WS_Client_CLI` to open many concurrent
persistent connections (`open()` per connection, one shared event loop via
`WS_Client_CLI::run()`) and drives the scenario's closed loop. Server readiness is
probed with a real RFC 6455 upgrade (it waits for `101 Switching Protocols`, not
just a TCP accept).

| Option | Default | Description |
|--------|---------|-------------|
| `--connections=N` | `514` | Persistent connections (echo) / receiver pool (broadcast) / batch size (connect) |
| `--duration=N` | `10` | Benchmark duration in seconds |
| `--client-workers=N` | auto | Client worker processes (see broadcast note below) |
| `--server-workers=N` | auto | Server worker processes |

---

## 🏁 Opponents

| Server | Language / Runtime | Mode | Used by |
|--------|-------------------|------|---------|
| **Bootgly** WS Server CLI | PHP (event-loop) | Baseline | all loads |

Each opponent lives in its own folder under `opponents/` and self-registers via
its own `@.php` (auto-discovered with `glob(opponents/*/@.php)` — you never edit
the case's main `@.php`).

---

## ⚙️ Configuration

### Sweeps

Options declared with `vary: true` in the case `options.php` schema — here,
`--server-workers` — accept **sweep values**: `8` (single), `1..24` (range),
`1..24:4` (range with step) or `1,2,4,8` (list). Each expanded value becomes one
execution **round** in the same process, producing one `.bench.marks` per round.

```bash
# server-workers sweep in one command (native SVG charts included):
./bootgly test benchmark WS_Server_CLI --loads=echo:* --server-workers=1..24:4 --results=charts
```

Three global options control the output: `--output=full|compact` (style; auto —
compact when sweeping), `--format=text|json` (json = machine-readable document
as the last stdout line) and `--results=marks|report|charts` (artifact levels —
report adds a `RESULTS-*.md`, charts adds native SVGs). Reports/charts land in
`bootgly/storage/tests/benchmarks/WS_Server_CLI/results/`; the run always ends with an
**Artifacts** footer pointing at every generated file.

### Contextual Help

```bash
# Tier 1 — list available cases
./bootgly test benchmark --help

# Tier 2 — case-specific options (config + runner + options.php)
./bootgly test benchmark WS_Server_CLI --help
```

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory. The load set is **required** —
there is no silent default.

```bash
# Echo throughput — both payload sizes
./bootgly test benchmark WS_Server_CLI --loads=echo:*

# Broadcast fan-out
./bootgly test benchmark WS_Server_CLI --loads=broadcast:*

# Handshake rate
./bootgly test benchmark WS_Server_CLI --loads=connect:*

# Specific opponent + load, custom config
./bootgly test benchmark WS_Server_CLI --opponents=bootgly --loads=echo:1 \
   --connections=256 --duration=15 --server-workers=8
```

### Global options

| Option | Description |
|--------|-------------|
| `--opponents=NAME,...` | Filter opponents by name |
| `--loads=<set>:<indices>` | **Required.** Load set + 1-based indices (`echo:*` for all, `echo:1` to filter) |
| `--server-workers=N|A..B|A..B:S|N,N` | Server workers — sweep values run one round each (see [Configuration](#-configuration)) |
| `--output=full|compact` | Output style (default: auto — compact when sweeping) |
| `--format=text|json` | Results serialization (json = last stdout line) |
| `--results=marks|report|charts` | Generated artifacts (report/charts land in `storage/tests/benchmarks/<case>/results/`) |

---

## ⚠️ Environment Notes

- **Port**: default **8085** (distinct from `HTTP_Server_CLI`:8082, `TCP_Server_CLI`:8083,
  `UDP_Server_CLI`:8084 to allow parallel runs).
- **Localhost loopback**: all tests run on `127.0.0.1` — network latency is not a factor.
- **No compression**: the client offers no permessage-deflate, so the benchmark
  measures raw framing (not zlib). Compressible payloads therefore travel uncompressed.
- **Broadcast is single-process**: fan-out throughput is bound by one client's
  receive loop, and extra client workers only add sender pairs that thrash the
  server's fan-out (throughput collapses as workers grow). The worker therefore
  forces a single client process for the `broadcast` set and `--client-workers`
  has no effect there; the connection count is capped at the `FD_SETSIZE` limit.
- **Connect metric**: the `connect` set reports **handshakes per second** in the
  `msg/s` column (it performs no messaging), and has no transfer/latency figure.
- **Warmup**: a warmup phase runs in the **active load's mode** before each
  benchmark. (A mismatched warmup — e.g. an echo client against a broadcast
  server — amplifies into a server backlog, so the mode is matched deliberately.)
- **CPU balance**: both the load generator and the server share CPU. The default
  uses `nproc / 2` workers to leave cores for the load generator.
- **Result variance**: results vary by hardware, OS, PHP version, and system load.
  Always compare on the **same machine, same session**.

---

## 📊 Results

> **[`results/README.md`](results/README.md)** indexes **every** load in the case
> (all 3 sets) and its benchmark coverage — the single source for "which loads
> exist and which were benchmarked". Charts are generated **per set** (each set
> has its own metric and Y scale).

Single opponent — one table, one row per load:

```
  Bootgly
  Load                Metric              Latency         Transfer
  Echo 32B            ...  msg/s          ...us           ...MB/s
  Echo 4KB            ...  msg/s          ...ms           ...GB/s
```

Each run saves a plain-text `.bench.marks` file (easy to diff or archive):

```
bootgly/storage/tests/benchmarks/WS_Server_CLI/<timestamp>_bench.marks
```

Reports are auto-generated from a range of `.bench.marks` files by
`bootgly_benchmarks/scripts/chart.py` (a connection or worker **sweep** gives the
chart its X axis). See [`scripts/README.md`](../scripts/README.md) for the full
`chart.py` CLI reference.

```bash
cd bootgly_benchmarks/scripts
.venv/bin/python3 chart.py \
   --marks '../../bootgly/storage/tests/benchmarks/WS_Server_CLI/*_bench.marks' \
   --out ../WS_Server_CLI/results/ --baseline Bootgly --x-key connections
```
