# bootgly_benchmarks/scripts

Tooling for analyzing `.bench.marks` files produced by `bootgly test benchmark`.

## `chart.py`

Generates throughput trend charts and comparison tables from a range of
`.bench.marks` files. Auto-detects the X axis (the config key that varies
between input files) and falls back to `--x-key` if multiple keys vary.

### Setup (one-time)

```bash
cd bootgly_benchmarks/scripts
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
```

Requires Python 3.10+ and matplotlib.

### Input format

`chart.py` expects `.bench.marks` files emitted by Bootgly v0.16-beta or
newer, which include a `# Config:` header block:

```
# Benchmark: HTTP_Server_CLI
# Date: 2026-05-31 23:12:46
# Config:
#   client-workers: 12
#   connections: 514
#   duration: 10
#   pipeline: 1
#   runner: tcp_client
#   load-set: techempower
#   server-workers: 16

[Bootgly][Database single query] rps=68,500 latency=6.80ms transfer=10.08MB/s
[Swoole TechEmpower][Database single query] rps=59,556 latency=7.82ms transfer=10.84MB/s
```

Files without `# Config:` are rejected with a clear error.

### Usage

```bash
# Server-workers sweep, DB loads — output goes to the case `results/`.
.venv/bin/python3 chart.py \
   --marks '../../bootgly/workdata/tests/benchmarks/HTTP_Server_CLI/2026-05-31_*.marks' \
   --out ../HTTP_Server_CLI/results/ \
   --baseline Bootgly

# Specify baseline opponent and force the X axis
.venv/bin/python3 chart.py \
   --marks 'workdata/.../*.marks' --out report/ \
   --baseline Bootgly --x-key client-workers

# Override the load-set token used in output filenames
.venv/bin/python3 chart.py \
   --marks '...' --out out/ --load-set my-sweep

# Custom report title
.venv/bin/python3 chart.py \
   --marks '...' --out out/ --title 'Server workers sweep — Bootgly 0.16 vs Swoole 6.2'
```

### Output

Files are written to `--out DIR` using the following pattern, keyed by the
load set and current timestamp:

```
RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.md
RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.throughput.png
RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.ratio.png
```

The load-set token comes from the marks `# Config: load-set` key
(case files surface it via `$Runner->meta`). Pass `--load-set NAME` to
override; defaults to `default` if neither marks nor CLI supplies one.

The Markdown report contains:

- **Environment** — auto-detected OS, CPU count, PHP and (when relevant)
  Swoole version, plus shared run configuration from the marks Config block.
- **Command** — a `for`-loop reproducing the sweep, with the case-specific
  env vars filled in when known.
- **Throughput chart** — one subplot per load, one line per opponent.
- **Baseline / opponent ratio chart** — when more than one opponent.
- **Comparison tables** — one per load, with `Δ` against the baseline.
- **Peaks** — best `req/s` per opponent and the gap at the baseline's peak.
- **Notes** — auto-generated observations (preflight failures, CPU
  oversubscription) and the list of source `.marks` files.

### CLI

```
chart.py --marks GLOB --out DIR [options]

  --marks GLOB              Glob of .marks files (quote to prevent shell expansion).
  --out DIR                 Output directory (created if missing).
  --baseline NAME           Baseline opponent for ratio chart and Δ column.
                            Default: first alphabetical.
  --x-key KEY               Force the X axis to this config key (skip auto-detect).
  --load-set NAME           Override load-set token in output filenames.
  --title TITLE             Custom report title.
```
