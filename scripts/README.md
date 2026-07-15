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

Run the parser/report regressions with:

```bash
MPLCONFIGDIR=/tmp/bootgly-matplotlib .venv/bin/python -m unittest discover -s tests
```

### Input format

`chart.py` expects `.bench.marks` files emitted by Bootgly v0.16-beta or
newer, which include a `# Config:` header block:

```
# Benchmark: HTTP_Server_CLI
# Date: 2026-05-31 23:12:46
# Config:
#   source-identity-version: raw-delta-manifest-v1
#   benchmarks-dirty: false
#   benchmarks-sha: edbc7eb000000000000000000000000000000000
#   benchmarks-tracked-diff-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
#   benchmarks-untracked-manifest-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
#   client-workers: 12
#   connections: 514
#   duration: 10
#   framework-dirty: false
#   framework-sha: 92775ac800000000000000000000000000000000
#   framework-tracked-diff-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
#   framework-untracked-manifest-sha256: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
#   framework-version: 0.24.0-beta
#   pipeline: 1
#   runner: tcp_client
#   load-set: techempower
#   server-workers: 16

[Bootgly][Database single query] rps=68,500 latency=6.80ms transfer=10.08MB/s
[Swoole][Database single query] rps=59,556 latency=7.82ms transfer=10.84MB/s
```

Files without `# Config:` are rejected with a clear error.

Bootgly captures `framework-sha` / `framework-dirty` and
`benchmarks-sha` / `benchmarks-dirty` before loading the case. For live Git
checkouts, it captures the recorded Git-visible source identity again after
every measured round and rejects a boundary mismatch. A Git-less packaged
source can only reconfirm its supplied fallback tuple, so its attributed source
layer or mount must remain immutable during the run. Dirty state means a staged index
delta, a physical tracked-byte/executable-mode delta, or a non-ignored
untracked input; submodules are unsupported and fail closed.
`source-identity-version` makes the canonical raw-delta/manifest encoding
explicit; different versions are not comparable as the same identity format.
The additive `framework-tracked-diff-sha256` and
`benchmarks-tracked-diff-sha256` fields contain the SHA-256 of an internal,
sorted, binary-safe delta of physical tracked file bytes and executable modes
against `HEAD`; Git clean/smudge, EOL, and external-diff filters are bypassed.
The
`framework-untracked-manifest-sha256` and
`benchmarks-untracked-manifest-sha256` fields contain the SHA-256 of an internal,
sorted, binary-safe manifest covering each non-ignored untracked path, its
Git-canonical mode/type, size, and file bytes or symlink target. Empty streams
produce the standard SHA-256 value shown in the example. Raw paths and file
contents are never emitted. When known, these fingerprints let reports
distinguish dirty worktrees that share the same commit SHA and dirty flag.
They cover physical tracked source plus non-ignored untracked inputs only:
ignored files, installed dependencies, environment, database state, and other
runtime inputs need separate provenance. Unmerged indexes, any submodule,
hidden index flags, special untracked files, an intermediate symlink in a
tracked path, or detected boundary instability produce `unknown` fingerprints
and dirty state.
When `.git` is unavailable, such as in a packaged container image, the producer
uses `BOOTGLY_FRAMEWORK_SHA`, `BOOTGLY_FRAMEWORK_DIRTY`,
`BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256`,
`BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256`, `BOOTGLY_BENCHMARKS_SHA`,
`BOOTGLY_BENCHMARKS_DIRTY`, `BOOTGLY_BENCHMARKS_TRACKED_DIFF_SHA256`, and
`BOOTGLY_BENCHMARKS_UNTRACKED_MANIFEST_SHA256` as validated fallbacks;
otherwise the value is explicitly `unknown`. The benchmark command rejects an
unknown, partial, or unsupported two-repository tuple before running; all four
hash fallbacks and both SHA/dirty pairs must therefore be supplied together. A
clean (`false`) tuple requires both standard empty SHA-256 digests; an
index-only staged delta may validly be dirty with both physical digests empty.
For a local `bootgly:full` build, generate all validated build arguments with
the same producer used by CI (run this from the framework repository):

```bash
docker build \
  $(php Bootgly/ACI/Tests/Benchmark/provenance.php . ../bootgly_benchmarks --docker-build-args) \
  -f Dockerfile --target full -t bootgly:full ..
```

Provenance keys are never selected automatically as a chart X axis. Generated
reports display shared source identity and warn when input marks mix revisions
or contain a dirty source tree.

### Usage

```bash
# One server-workers sweep. Replace RUN_ID with the exact invocation to chart.
.venv/bin/python3 chart.py \
   --marks '../../bootgly/storage/tests/benchmarks/HTTP_Server_CLI/runs/RUN_ID/marks/*_bench.marks' \
   --out ../HTTP_Server_CLI/results/ \
   --baseline Bootgly

# Specify baseline opponent and force the X axis
.venv/bin/python3 chart.py \
   --marks 'storage/.../*.marks' --out report/ \
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
