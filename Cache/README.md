# ⏱️ Benchmark — Cache

Per-driver, per-operation profiling of the **Bootgly Cache** layer
(`Bootgly\ABI\Resources\Cache`). Every driver runs the **same** workload
(`scenarios.php`) and the results render as a **driver × operation matrix** — so
you can see exactly which driver wins for each operation and spot hotspots.

This is an **internal** benchmark (Bootgly drivers vs each other), not a
comparison against external libraries.

---

## 📋 Table of Contents

- [Prerequisites](#-prerequisites)
- [Installation](#-installation)
- [Opponents (drivers)](#-opponents-drivers)
- [Operations](#-operations)
- [Runner](#-runner)
- [Running](#-running)
- [Reading the matrix](#-reading-the-matrix)
- [Results](#-results)

---

## ✅ Prerequisites

| Dependency | Purpose | Required |
|-----------|---------|----------|
| **PHP** ≥ 8.4 | Runtime | ✅ |
| **ext-apcu** + `apc.enable_cli=1` | `APCu` driver | Optional — driver shows **N/A** without it |
| **ext-sysvshm** + **ext-sysvsem** | `Shared` driver | Optional — **N/A** without it |
| **Redis server** on `127.0.0.1:6379` | `Redis` driver | Optional — **N/A** when unreachable |

No Composer dependencies. The `File` driver is always available, so the case
always produces at least one column.

---

## 🔧 Installation

> `bootgly` and `bootgly_benchmarks` sit **side by side** in the same parent
> directory — the runner resolves the framework via that relative layout.

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

Nothing else to install. To benchmark a driver, just make its backend available
(load the extension / start Redis) and re-run — unavailable drivers self-skip
to **N/A**.

---

## 🎯 Opponents (drivers)

Each `opponents/<driver>/bootgly.php` builds a `Cache` with one driver, runs the
shared workload, and writes a `label → {time, memory}` map. An unavailable
backend makes the script exit non-zero, and that driver shows **N/A** for every
operation.

| Opponent | Driver | Scope | Availability guard |
|----------|--------|-------|--------------------|
| **File** | `file` | Per-host, on disk | always available |
| **APCu** | `apcu` | Per-process | `ext-apcu` + `apc.enable_cli` |
| **Shared** | `shared` | Cross-worker, per-host (System V shm+sem) | `shm_attach` + `sem_get` |
| **Redis** | `redis` | Networked / shared | reachable `127.0.0.1:6379` |

---

## 🧪 Operations

The workload (`scenarios.php`, `N = 2000` keys, `M = 500` tagged keys) times
these operations — each becomes a row in the matrix:

| Label | What it measures |
|-------|------------------|
| `store` | write N small scalars |
| `store:large` | write N serialized arrays (serialize + payload size cost) |
| `store:tagged` | write M keys with 2 tags each (pipelined SET+SADD on Redis) |
| `fetch:hit` | read N existing keys |
| `fetch:miss` | read N absent keys |
| `check` | N existence checks |
| `increment` | N atomic increments on one hot key (rate-limiter primitive) |
| `tags:invalidate` | store M tagged keys, then drop the tag |
| `resolve:miss` | get-or-compute, all misses (compute + store) |
| `resolve:hit` | get-or-compute, all hits |
| `mixed` | 80% fetch / 20% store interleaved |
| `delete` | delete N keys |

Memory is the per-operation `memory_get_usage()` delta — useful for relative
comparison, but small/noisy for cheap operations.

---

## 🏃 Runner

Uses the **Code** runner (local subprocess, time + memory). Defaults set by the
case: `iterations = 3` (best per label kept), `warmup = 1`.

| Option | Default | Meaning |
|--------|---------|---------|
| `--opponents=a,b` | all | Restrict to specific drivers (e.g. `file,shared`) |
| `--iterations=N` | 3 | Runs per driver; best time per operation is kept |
| `--warmup=N` | 1 | Discarded warmup runs |
| `--timeout=N` | 120 | Per-execution timeout (seconds) |

---

## ▶️ Running

```bash
cd bootgly

# All drivers (unavailable ones show N/A)
./bootgly test benchmark Cache --loads=default:* --opponents=file,apcu,shared,redis

# Only the always-available driver
./bootgly test benchmark Cache --loads=default:* --opponents=file

# File vs Shared, more stable
./bootgly test benchmark Cache --loads=default:* --opponents=file,shared --warmup=2 --iterations=5
```

Each run also writes
`bootgly/storage/tests/benchmarks/Cache/runs/<run-id>/marks/result_bench.marks`
with one `[driver][operation] time=… memory=…` line per cell, for trend
tracking.

---

## 📖 Reading the matrix

One table per operation, drivers ranked fastest-first (🥇/🥈/🥉):

```
── increment ──
Opponent    Time            Memory          Position
────────────────────────────────────────────────────
Shared      0.073270s       792 B           🥇
File        0.535935s       792 B           🥈
APCu        N/A             N/A             🥉
Redis       N/A             N/A             #4
```

---

## 📊 Results

> Numbers are **machine-specific** — run it on your target hardware. Example
> below: PHP 8.4, WSL2, all four drivers available, `iterations=3 warmup=1`,
> `N=2000`. Times are best-of-3 in seconds; **bold** is the fastest driver for
> that operation.

| Operation | APCu | Shared | File | Redis |
|-----------|------|--------|------|-------|
| store | **0.0012** | 0.0294 | 0.1894 | 0.1797 |
| store:large | **0.0041** | 0.0971 | 0.2063 | 0.1824 |
| store:tagged | **0.0181** | 0.1300 | 0.0618 | 0.0472 |
| fetch:hit | **0.0006** | 0.0094 | 0.0331 | 0.1746 |
| fetch:miss | **0.0006** | 0.0287 | 0.0062 | 0.1751 |
| check | **0.0005** | 0.0108 | 0.0228 | 0.1724 |
| increment | **0.0006** | 0.0962 | 0.4115 | 0.1769 |
| tags:invalidate | **0.0002** | 0.0346 | 0.0121 | 0.0011 |
| resolve:miss | **0.0016** | 0.2097 | 0.2122 | 0.3518 |
| resolve:hit | **0.0008** | 0.0627 | 0.0328 | 0.1747 |
| mixed | **0.0007** | 0.0187 | 0.0945 | 0.1789 |
| delete | **0.0006** | 0.1552 | 0.0444 | 0.1764 |

**APCu** floors every row — it is per-process userland memory: native
`apcu_*`, no serialization to a segment, no semaphore, no syscalls. The catch
is **scope**: APCu is visible only within one process, so it is *not* a
cross-worker backend (use it for per-worker hot data, not the shared rate
limiter).

**Shared** is the fastest **cross-worker** driver; **File** wins only the
cheap miss/delete/tags paths (no segment write + index bookkeeping). Pick by
scope first, speed second: per-process → APCu; per-host multi-worker → Shared;
across hosts → Redis; zero-setup baseline → File.

**Redis** is round-trip-bound here: single-key operations cost one blocking
command over localhost TCP (~90 µs/op), so those rows sit near a flat ~0.18 s
regardless of payload size. Multi-command operations are already batched into
single round-trips (see *Redis — round-trip batching* below) — note Redis
**winning** `tags:invalidate` and beating File on `store:tagged`. Squeezing the
remaining flat rows would require a batch API (fetch/store many keys per call) —
a contract change, future work.

### Improvements applied (before → after)

This benchmark drove two cache-core fixes (validated by the full cache test
suite + PHPStan, re-measured here as the acceptance gate):

**Shared — sharded live-key index.** The driver tracked every live key in a
single shared-memory var, rewritten on every create/delete. As the keyspace
grew this made each `store`/`delete` O(N) and the whole workload O(N²). The
index is now split across 256 buckets (`id % 256`), so a create/delete rewrites
only one small bucket:

| Operation | Before | After | Speedup |
|-----------|-------:|------:|--------:|
| store | 0.128 | 0.030 | ~4.3× |
| store:large | 0.403 | 0.102 | ~3.9× |
| resolve:miss | 0.689 | 0.195 | ~3.5× |
| delete | 0.667 | 0.148 | ~4.5× |

(`tags:invalidate` 0.011 → 0.031 — it now removes members per-bucket; a rare op,
an acceptable trade for the across-the-board wins.)

**File — lazy shard-dir creation.** `store`/`increment` no longer `stat` the
shard directory on every call; the directory is created only when the first
write/open fails. `store` ~0.245 → ~0.177, `store:large` ~0.256 → ~0.187.

**Redis — round-trip batching.** The driver issued one blocking command per
step, so multi-command operations multiplied ~90 µs round-trips. Now: tagged
stores pipeline `SET` + `SADD`s into a single round-trip; `invalidate` replaces
per-member `DEL`s with chunked variadic `UNLINK` (502 RTTs → 2 for 500
members); `clear` unlinks each SCAN batch in one command; the facade's
`resolve()` decides hit/miss with a single `GET` instead of `EXISTS`+`GET`
(stored `null` now counts as a miss — do not cache `null`); `TCP_NODELAY` is
set on the native socket and short `fwrite`s are retried. A `persistent`
config key (default `false`) opts into persistent connections:

| Operation | Before | After | Speedup |
|-----------|-------:|------:|--------:|
| tags:invalidate | 0.0452 | 0.0011 | ~39× |
| resolve:hit | 0.3767 | 0.1747 | ~2.2× |
| store:tagged | (3 RTT/key) | 0.0472 (1 RTT/key) | ~2.6× |

The `resolve()` single-fetch also lifted **every** driver's `resolve:hit`
(File 0.055 → 0.033, Shared 0.112 → 0.063). `resolve:miss` is unchanged by
design — the miss path was already two steps (probe + store) before and after.

**Remaining floor — File `increment`.** A file-backed counter is inherently
bound by open + `flock` + truncate + write + `fflush` + close per call (the
`fflush` is required so other workers see the new value before the lock is
released). For hot counters use the **Shared**, **Redis**, or **APCu** driver.
