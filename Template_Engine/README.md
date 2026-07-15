# ⏱️ Benchmark — Template Engine (`foreach` directive)

Benchmarks the **Bootgly Template Engine** `foreach` directive against Laravel
Blade, iterating over a large collection (1,000,000 items) — measuring raw
directive rendering speed without sacrificing features.

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
| **Composer** | Installs the Laravel Blade opponent | Only for the `laravel` opponent |

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

The Bootgly opponent needs nothing extra. For the Laravel Blade opponent,
install its dependencies once:

```bash
cd bootgly_benchmarks/Template_Engine/bootables/laravel
composer install
```

---

## 🎯 Loads

This case uses the **Code** runner, so there are no request loads — each opponent
renders one fixed template as a subprocess:

| Workload | Description |
|----------|-------------|
| `foreach` render | A `@foreach` directive iterating over **1,000,000 items**, empty body |

The timer wraps only the `foreach` iteration (start recorded just before, end
immediately after) so other factors do not affect accuracy.

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

| Opponent | Runtime | Engine | CLI name |
|-----------|---------|--------|----------|
| **Bootgly** | PHP | `ABI\Templates\Template` | `bootgly` |
| **Laravel** | PHP | Blade (`Illuminate\View`) | `laravel` |

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
./bootgly test benchmark Template_Engine --help
```

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory.

```bash
# All opponents, defaults
./bootgly test benchmark Template_Engine --loads=default:* --opponents=bootgly,laravel

# Both opponents, 3 iterations (best kept)
./bootgly test benchmark Template_Engine --loads=default:* --opponents=bootgly,laravel --iterations=3

# Bootgly only
./bootgly test benchmark Template_Engine --loads=default:* --opponents=bootgly
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

The goal is to determine which template engine performs best when iterating over a
large collection of data. The benchmark focuses on rendering time and excludes any
application processing within the loop. Executed through a `CLI` interface over a
collection of **1,000,000 items**.

The Bootgly engine does **not** sacrifice any feature available in Blade's
`foreach`:

- Access to the loop variable (`loop->index`, `loop->count`, `loop->first`, `loop->last`, …)
- `@continue` and `@break` directives within the loop

**Interface:** ABI &nbsp;·&nbsp; **Platform:** none &nbsp;·&nbsp; **Workable:** none

---

## ⚠️ Environment Notes

- **Subprocess isolation**: each opponent runs in its own PHP process; memory is the peak of that process.
- **Result variance**: exact numbers and relative ratios vary with hardware,
  OS, PHP, and host activity. Compare opponents within a controlled paired run;
  cross-environment ordering and ratios are not guaranteed.
- **Best-of-N**: with `--iterations>1`, the fastest run is reported to reduce noise.

---

## 📊 Results

> Bootgly Template Engine is ≈ 9x faster than Laravel Blade (without sacrificing features).

| Framework | Result | Position |
|-----------|--------|----------|
| Bootgly | 0.046s | 🥇 First (winner) |
| Laravel | 0.438s | 🥈 Second |

Each run also saves a plain-text `.bench.marks` file at
`bootgly/storage/tests/benchmarks/Template_Engine/runs/<run-id>/marks/result_bench.marks`.
See
[`scripts/README.md`](../scripts/README.md) for chart generation.
