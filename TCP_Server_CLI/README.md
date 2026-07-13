# ⏱️ Benchmark — TCP Server CLI

Raw TCP server benchmark for **Bootgly TCP Server CLI**, comparing PHP frameworks
at the **socket level** — without HTTP routing or middleware overhead. It measures
pure framework I/O cost: accept, read, write, close.

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
| **PHP** ≥ 8.4 | Server runtime | ✅ |
| **lsof** | Port detection | ✅ |
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
`bootgly project Benchmark/TCP_Server_CLI` command.

---

## 🎯 Loads

A *load* is one PHP file under `loads/` describing a request pattern for the
TCP client generator. The benchmark uses a **generic message/delimiter** protocol:

- **Echo** — client sends a newline-terminated message → server echoes it back verbatim.
- **HTTP Raw** — client sends a raw `GET / HTTP/1.1` request → server responds with a
  fixed `HTTP/1.1 200 OK` (no routing, no middleware).

Servers implement a **dual-mode handler**: if the data starts with `GET `, respond
with HTTP; otherwise, echo the data back.

| # | File | Label | Group |
|---|------|-------|-------|
| 1 | `1.1.1-echo_small.php` | Echo 32 bytes | Echo |
| 2 | `1.2.1-http_raw.php` | HTTP raw (Hello World) | HTTP Raw |

---

## 🔧 Runners

Uses the **TCP_Raw** runner (`runners/TCP_Raw.php`), which spawns a standalone
worker process per load. The worker uses `TCP_Client_CLI` to open many concurrent
connections and measure throughput. `--runner=TCP_Client` is accepted as an alias
(for comparisons against `HTTP_Server_CLI`) but still runs the raw TCP protocol.

| Option | Default | Description |
|--------|---------|-------------|
| `--connections=N` | `514` | Number of TCP connections |
| `--duration=N` | `10` | Benchmark duration in seconds |
| `--client-workers=N` | auto | Number of client worker processes |
| `--server-workers=N` | auto | Number of server worker processes |

---

## 🏁 Opponents

| Server | Language / Runtime | Mode | Used by |
|--------|-------------------|------|---------|
| **Bootgly** TCP Server CLI | PHP (event-loop) | Baseline | all loads |

Each opponent lives in its own folder under `opponents/` and self-registers via
its own `autoboot.php` (auto-discovered with `glob(opponents/*/autoboot.php)` — you never edit the
case's main `autoboot.php`).

---

## ⚙️ Configuration

### Sweeps

Options declared with `vary: true` in the case `options.php` schema — here,
`--server-workers` — accept **sweep values**: `8` (single), `1..24` (range),
`1..24:4` (range with step) or `1,2,4,8` (list). Each expanded value becomes one
execution **round** in the same process, producing one `.bench.marks` per round.

```bash
# server-workers sweep in one command (native SVG charts included):
./bootgly test benchmark TCP_Server_CLI --loads=default:* --server-workers=1..24:4 --results=charts
```

Three global options control the output: `--output=full|compact` (style; auto —
compact when sweeping), `--format=text|json` (json = prints only the
machine-readable JSON document, human output suppressed) and `--results=marks|report|charts` (artifact levels —
report adds a `RESULTS-*.md`, charts adds native SVGs). Reports/charts land in
`bootgly/storage/tests/benchmarks/TCP_Server_CLI/results/`; the run always ends with an
**Artifacts** footer pointing at every generated file.

### Contextual Help

```bash
# Tier 1 — list available cases
./bootgly test benchmark --help

# Tier 2 — case-specific options (config + runner + options.php)
./bootgly test benchmark TCP_Server_CLI --help
```

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory.

```bash
# All loads, the Bootgly baseline (single set: default)
./bootgly test benchmark TCP_Server_CLI --loads=default:*

# Specific opponent and load
./bootgly test benchmark TCP_Server_CLI --opponents=bootgly --loads=default:1

# Custom runner options
./bootgly test benchmark TCP_Server_CLI --loads=default:* --connections=256 --duration=15 --server-workers=8
```

### Global options

| Option | Description |
|--------|-------------|
| `--opponents=NAME,...` | Filter opponents by name |
| `--loads=<set>:<indices>` | **Required.** Load set + 1-based indices (`default:*` for all, `default:1,2` to filter) |
| `--server-workers=N|A..B|A..B:S|N,N` | Server workers — sweep values run one round each (see [Configuration](#-configuration)) |
| `--output=full|compact` | Output style (default: auto — compact when sweeping) |
| `--format=text|json` | Results serialization (json = prints only the JSON document) |
| `--results=marks|report|charts` | Generated artifacts (report/charts land in `storage/tests/benchmarks/<case>/results/`) |

---

## ⚠️ Environment Notes

- **CPU balance**: both the load generator and the server share CPU. The default uses `nproc / 2` workers to leave cores for the load generator.
- **Port**: default **8083** (distinct from `HTTP_Server_CLI`:8082 to allow parallel runs).
- **Localhost loopback**: all tests run on `127.0.0.1` — network latency is not a factor.
- **Result variance**: results vary by hardware, OS, PHP version, and system load. Always compare on the **same machine, same session**.
- **Warmup**: a warmup phase runs before each benchmark to stabilize TCP buffers and worker pools.

---

## 📊 Results

Single opponent — one table, one row per load:

```
  Bootgly
  Load                Metric              Latency         Transfer
  Echo 32 bytes       ...  req/s          ...us           ...MB/s
  HTTP raw            ...  req/s          ...us           ...MB/s
```

Each run saves a plain-text `.bench.marks` file (easy to diff or archive):

```
bootgly/storage/tests/benchmarks/TCP_Server_CLI/<timestamp>_bench.marks
```

Reports are auto-generated from a range of `.bench.marks` files by
`bootgly_benchmarks/scripts/chart.py` into this case's `results/` folder. See
[`scripts/README.md`](../scripts/README.md) for the full `chart.py` CLI reference.
