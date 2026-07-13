# ⏱️ Benchmark — Progress Bar

Benchmarks for the **Bootgly Progress Bar** CLI component — how fast the component
renders a fixed number of progress units in a tight loop, compared with the
Symfony/Laravel ProgressBar.

| ![](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/results/bootgly-progress_bar-benchmark.1.png "Render 6x faster than Symfony / Laravel") |
|:--:|
| *Progress component (with Bar) — Render ≈ 7x faster than Symfony / Laravel* |

---

## 📋 Table of Contents

- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Loads](#-loads)
- [Runners](#-runners)
- [Opponents](#-opponents)
- [Configuration](#-configuration)
- [Running Benchmarks](#-running-benchmarks)
- [Context](#-context)
- [Environment Notes](#-environment-notes)
- [Results](#-results)

---

## ✅ Prerequisites

| Dependency | Purpose | Required |
|-----------|---------|----------|
| **PHP** ≥ 8.4 | Runtime | ✅ |
| **Composer** | Installs the Laravel/Symfony opponent | Only for the `laravel` opponent |

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

The Bootgly opponent needs nothing extra. For the Laravel/Symfony opponent,
install its dependencies once:

```bash
cd bootgly_benchmarks/Progress_Bar/bootables/laravel
composer install
```

---

## 🎯 Loads

This case uses the **Code** runner, so there are no request loads — each opponent
runs one fixed workload as a subprocess:

| Workload | Description |
|----------|-------------|
| Progress render | **5,000 iterations**, fixed template (description + bar + percent + elapsed/eta/rate), throttle disabled for maximum render rate |

Rendering-limiting settings are disabled so the measurement isolates raw render
throughput — no application processing happens inside the loop.

---

## 🔧 Runners

Uses the **Code** runner (`runners/Code.php`), which executes each opponent as a
subprocess and measures wall-clock time and peak memory. Best result over
`--iterations` is kept.

| Option | Default | Description |
|--------|---------|-------------|
| `--iterations=N` | `1` | Iterations per opponent (best is kept) |
| `--timeout=N` | `120` | Timeout in seconds per execution |
| `--warmup=N` | `0` | Warmup iterations (discarded) |

---

## 🏁 Opponents

| Opponent | Runtime | Component | CLI name |
|-----------|---------|-----------|----------|
| **Bootgly** | PHP | `CLI\UI\Components\Progress` | `bootgly` |
| **Laravel / Symfony** | PHP | `Symfony\Console\Helper\ProgressBar` | `laravel` |

Each opponent lives in its own folder under `opponents/` and self-registers via
its own `autoboot.php` (auto-discovered with `glob(opponents/*/autoboot.php)` — you never edit the
case's main `autoboot.php`).

---

## ⚙️ Configuration

### Contextual Help

```bash
# Tier 1 — list available cases
./bootgly test benchmark --help

# Tier 2 — case-specific options (Code runner options + opponents)
./bootgly test benchmark Progress_Bar --help
```

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory.

```bash
# All opponents, defaults
./bootgly test benchmark Progress_Bar

# Both opponents, 3 iterations (best kept)
./bootgly test benchmark Progress_Bar --opponents=bootgly,laravel --iterations=3

# Bootgly only
./bootgly test benchmark Progress_Bar --opponents=bootgly
```

### Global options

| Option | Description |
|--------|-------------|
| `--opponents=NAME,...` | Filter opponents (`bootgly`, `laravel`) |
| `--iterations=N` | Iterations per opponent |
| `--timeout=N` | Timeout in seconds per execution |
| `--warmup=N` | Warmup iterations (discarded) |

---

## 🔍 Context

The context of this benchmark is **framework performance only** — specifically how
fast the CLI component can render units of progress in a loop with a fixed number
of iterations.

- No application processing runs inside the loop.
- Settings that limit rendering are removed for maximum performance.

**Interface:** CLI &nbsp;·&nbsp; **Platform:** none &nbsp;·&nbsp; **Workable:** none

---

## ⚠️ Environment Notes

- **Subprocess isolation**: each opponent runs in its own PHP process; memory is the peak of that process.
- **Result variance**: exact numbers vary by hardware, OS, and PHP version — but the **relative** proportion between opponents stays consistent across environments.
- **Best-of-N**: with `--iterations>1`, the fastest run is reported to reduce noise.

---

## 📊 Results

> Bootgly Progress Bar is ≈ 7x faster than Laravel / Symfony Progress Bar.

| Framework | Result | Position |
|-----------|--------|----------|
| Bootgly | 6.49s | 🥇 First (winner) |
| Laravel / Symfony | 45s | 🥈 Second |

### Bootgly Progress Bar

![bootgly-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/results/bootgly-progress_bar-benchmark.1.png)

### Laravel / Symfony Progress Bar

![laravel-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/results/laravel-progress_bar-benchmark.1.png)

Each run also saves a plain-text `.bench.marks` file under
`bootgly/storage/tests/benchmarks/Progress_Bar/`. See
[`scripts/README.md`](../scripts/README.md) for chart generation.
