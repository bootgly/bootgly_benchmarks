# WS_Server_CLI — benchmark results

This case ships **4 loads** across **3 load sets**. Each set runs as its own
`bootgly test benchmark` invocation (distinct **server mode** and **metric**) and
is charted **separately** by `chart.py` — charts are per-set because the metrics
and Y scales differ. The table below lists **every** load in the case and whether
it has been benchmarked.

## Loads & coverage

| Set | # | Load | Mode | Metric | Sweep axis | Benchmarked | Peak (reference machine) | Report |
|-----|---|------|------|--------|------------|:-----------:|--------------------------|--------|
| `echo` | 1 | Echo 32B | echo | msg/s | server-workers @ 512 conns | ✅ | **873,804** @ 11 server-workers | `RESULTS-echo-*` |
| `echo` | 2 | Echo 4KB | echo | msg/s | — | ⏳ | not in the current sweep | — |
| `echo` | 3 | Echo 32B (binary) | echo | msg/s | server-workers @ 512 conns | ✅ | **985,424** @ 12 server-workers | `RESULTS-echo-binary-*` |
| `echo` | 4 | Echo 32B (pipelined x16) | echo | msg/s | server-workers @ 512 conns | ✅ | **1,116,803** @ 10 server-workers | `RESULTS-echo-pipelined-*` |
| `broadcast` | 1 | Broadcast fan-out | broadcast | msg/s | connections @ 12 server-workers | ✅ | **326,634** @ 128 conns | `RESULTS-broadcast-*` |
| `connect` | 1 | Connect rate | connect | conn/s | connections @ 12 server-workers | ✅ | **64,548** @ 1024 conns | `RESULTS-connect-*` |

**Coverage: 5 / 6 loads benchmarked** — Echo 4KB is intentionally out of the current
Echo-32B-focused sweep (re-run it with the same methodology when needed).

### Echo 32B — throughput regimes (@ 11 server-workers, 512 connections, 10 s)

| Variant | msg/s | Latency | vs text |
|---------|------:|--------:|--------:|
| Text (1 in flight) | 841,702 | 556 µs | — |
| Binary (1 in flight) | 952,981 | 492 µs | +13% |
| **Pipelined x16** | **1,085,401** | 6.90 ms* | **+29%** |

\* Pipelined latency is queue-inclusive (16 frames deep), not a round trip.

The plain (text, 1-in-flight) echo is **round-trip-latency-bound** — its ceiling is
`connections / RTT`, not frame-processing speed. **Binary** removes the server's
UTF-8 validation (~13%). **Pipelining** (16 frames in flight, the HTTP-plaintext
depth) moves it into the **frame-processing-bound** regime and breaks **1M msg/s**,
on par with `HTTP_Server_CLI` plaintext (which is pipelined). So the lower closed-loop
number is a methodology difference, not a slower server.

The table above fixes server workers at 11 to isolate the variable; each variant also
has its **own full server-workers sweep (1 → 24)** with its own optimum:

| Variant | Peak | @ server-workers | Chart |
|---------|-----:|:----------------:|-------|
| Text | 873,804 | 11 | `RESULTS-echo-*` |
| Binary | 985,424 | 12 | `RESULTS-echo-binary-*` |
| **Pipelined x16** | **1,116,803** | 10 | `RESULTS-echo-pipelined-*` |

> **Echo 32B** is the headline throughput figure: a **server-workers sweep (1 → 24)**
> at the peak connection count (512), like `HTTP_Server_CLI`. It peaks at **11
> server workers** (≈ `nproc` once the 12 client workers are counted) then declines
> as server + client workers oversubscribe the 24 logical CPUs.
>
> **Broadcast** and **Connect** are `connections` sweeps (128 / 256 / 512 / 1024) at
> a fixed 12 server workers. Reference machine: 24 logical CPUs, PHP 8.4, loopback,
> no compression, 10 s/run. Numbers vary by hardware — open each `RESULTS-<set>-*.md`
> for the full per-point table and the throughput / latency charts.

## How results are organized

- One report set per load set: `RESULTS-<set>-<timestamp>.md` + matching
  `.chart.throughput.png` / `.chart.latency.png`.
  - `RESULTS-echo-*` — Echo 32B (text), server-workers sweep @ 512 conns (msg/s)
  - `RESULTS-echo-binary-*` — Echo 32B (binary), server-workers sweep @ 512 conns (msg/s)
  - `RESULTS-echo-pipelined-*` — Echo 32B (pipelined x16), server-workers sweep @ 512 conns (msg/s)
  - `RESULTS-broadcast-*` — Broadcast fan-out, connections sweep (msg/s)
  - `RESULTS-connect-*` — Connect rate, connections sweep (conn/s)
- Source `.bench.marks` files live below
  `bootgly/storage/tests/benchmarks/WS_Server_CLI/runs/<run-id>/marks/`
  (gitignored).

## Regenerate

From `bootgly/`. **Echo 32B (text / binary / pipelined) — server-workers sweep**
@ 512 connections (the headline throughput runs):

```bash
for load in 1 3 4; do            # 1 = text, 3 = binary, 4 = pipelined x16
  for sw in $(seq 1 24); do
    php bootgly test benchmark WS_Server_CLI --opponents=bootgly \
      --loads=echo:$load --connections=512 --duration=10 --server-workers=$sw --client-workers=12
  done
done
```

**Broadcast / Connect — connections sweep** @ 12 server workers:

```bash
for set in broadcast connect; do
  for c in 128 256 512 1024; do
    php bootgly test benchmark WS_Server_CLI \
      --opponents=bootgly --loads=$set:* --connections=$c --duration=10 --server-workers=12
  done
done
```

Then chart **one variant per chart** — `chart.py` uses one X point per marks file,
so never mix sweeps. The three echo variants share `load-set: echo`, so stage them
by exact **load label**; broadcast/connect stage by load set. Staging copies into a
fresh temp dir — the source `.marks` are never deleted. From
`bootgly_benchmarks/scripts`:

```bash
RUNS=../../bootgly/storage/tests/benchmarks/WS_Server_CLI/runs
chart () {  # <grep -F pattern> <output load-set> [title]
  stage=$(mktemp -d)
  grep -rlF --include='*_bench.marks' "$1" "$RUNS" |
    xargs -r -I{} cp "{}" "$stage"/
  .venv/bin/python3 chart.py --marks "$stage/*_bench.marks" \
    --out ../WS_Server_CLI/results/ --baseline Bootgly --load-set "$2" ${3:+--title "$3"}
}
chart '[Echo 32B]'                 echo           'WS_Server_CLI — Echo 32B: server-workers sweep'
chart '[Echo 32B (binary)]'        echo-binary    'WS_Server_CLI — Echo 32B (binary): server-workers sweep'
chart '[Echo 32B (pipelined x16)]' echo-pipelined 'WS_Server_CLI — Echo 32B (pipelined x16): server-workers sweep'
chart 'load-set: broadcast'        broadcast
chart 'load-set: connect'          connect
```
