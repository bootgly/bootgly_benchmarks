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
- [Competitors](#-competitors)
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
| **Composer** | Installs the Laravel Blade competitor | Only for the `laravel` competitor |

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

The Bootgly competitor needs nothing extra. For the Laravel Blade competitor,
install its dependencies once:

```bash
cd bootgly_benchmarks/Template_Engine/bootables/laravel
composer install
```

---

## 🎯 Loads

This case uses the **Code** runner, so there are no request loads — each competitor
renders one fixed template as a subprocess:

| Workload | Description |
|----------|-------------|
| `foreach` render | A `@foreach` directive iterating over **1,000,000 items**, empty body |

The timer wraps only the `foreach` iteration (start recorded just before, end
immediately after) so other factors do not affect accuracy.

---

## 🔧 Runners

Uses the **Code** runner (`runners/Code.php`), which executes each competitor as a
subprocess and measures wall-clock time and peak memory. Best result over
`--iterations` is kept.

| Option | Default | Description |
|--------|---------|-------------|
| `--iterations=N` | `1` | Iterations per competitor (best is kept) |
| `--timeout=N` | `120` | Timeout in seconds per execution |
| `--warmup=N` | `0` | Warmup iterations (discarded) |

---

## 🏁 Competitors

| Competitor | Runtime | Engine | CLI name |
|-----------|---------|--------|----------|
| **Bootgly** | PHP | `ABI\Templates\Template` | `bootgly` |
| **Laravel** | PHP | Blade (`Illuminate\View`) | `laravel` |

Each competitor lives in its own folder under `opponents/` and self-registers via
its own `@.php` (auto-discovered with `glob(opponents/*/@.php)` — you never edit the
case's main `@.php`).

---

## ⚙️ Configuration

### Contextual Help

```bash
# Tier 1 — list available cases
./bootgly test benchmark --help

# Tier 2 — case-specific options (Code runner options + competitors)
./bootgly test benchmark Template_Engine --help
```

---

## 🚀 Running Benchmarks

All commands run from the **bootgly** directory.

```bash
# All competitors, defaults
./bootgly test benchmark Template_Engine

# Both competitors, 3 iterations (best kept)
./bootgly test benchmark Template_Engine --competitors=bootgly,laravel --iterations=3

# Bootgly only
./bootgly test benchmark Template_Engine --competitors=bootgly
```

### Global options

| Option | Description |
|--------|-------------|
| `--competitors=NAME,...` | Filter competitors (`bootgly`, `laravel`) |
| `--iterations=N` | Iterations per competitor |
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

- **Subprocess isolation**: each competitor runs in its own PHP process; memory is the peak of that process.
- **Result variance**: exact numbers vary by hardware, OS, and PHP version — but the **relative** proportion between competitors stays consistent across environments. If Bootgly is faster in one environment, it is faster in others, even if the exact speedup factor varies.
- **Best-of-N**: with `--iterations>1`, the fastest run is reported to reduce noise.

---

## 📊 Results

> Bootgly Template Engine is ≈ 9x faster than Laravel Blade (without sacrificing features).

| Framework | Result | Position |
|-----------|--------|----------|
| Bootgly | 0.046s | 🥇 First (winner) |
| Laravel | 0.438s | 🥈 Second |

Each run also saves a plain-text `.bench.marks` file under
`bootgly/workdata/tests/benchmarks/Template_Engine/`. See
[`scripts/README.md`](../scripts/README.md) for chart generation.
