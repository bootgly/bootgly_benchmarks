#!/usr/bin/env python3
"""Generate trend report and charts from a range of Bootgly .bench.marks files.

Parses .marks files (with the `# Config:` header introduced in Bootgly
v0.16-beta), groups results by competitor + load, and renders three files
keyed by the load set and current timestamp:

    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.md
    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.throughput.png
    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.ratio.png

The X axis is auto-detected: among the config keys present in every input file
(`server-workers`, `client-workers`, `connections`, `duration`, `pipeline`),
the one with the most distinct values is picked. Pass `--x-key NAME` to
override. Files that lack a `# Config:` header are rejected (use Bootgly
v0.16-beta or newer).

The load-set token comes from the marks `# Config: load-set` key
(case files surface it via `$Runner->meta`). Pass `--load-set NAME` to
override; defaults to `default` if neither marks nor CLI supplies one.

Usage:
    chart.py --marks 'workdata/.../*.marks' --out OUTPUT_DIR \\
             [--baseline COMPETITOR] [--x-key KEY] [--load-set NAME] \\
             [--title TITLE]
"""

from __future__ import annotations

import argparse
import datetime
import glob
import os
import re
import subprocess
import sys
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt

CONFIG_LINE = re.compile(r"^#\s{3}([a-zA-Z][\w-]*):\s*(.+?)\s*$")
RESULT_LINE = re.compile(r"^\[([^\]]+)\]\[([^\]]+)\]\s*(.*)$")
KV = re.compile(r"(\w+)=([^\s]+)")

# Preferred X axis when multiple config keys vary.
X_PREFERENCE = (
    "server-workers",
    "client-workers",
    "connections",
    "duration",
    "pipeline",
)

# Color palette for competitors and loads.
COMPETITOR_COLORS = ["#3b82f6", "#f97316", "#10b981", "#a855f7", "#ef4444", "#0ea5e9"]
LOAD_COLORS = [
    "#1d4ed8", "#7c3aed", "#dc2626", "#059669", "#0891b2", "#d97706",
    "#be185d", "#65a30d", "#0369a1", "#9333ea", "#ea580c", "#0f766e",
]

# Subplot title decorations: load label -> (route, short descriptor).
# Falls back to the raw label when no entry matches.
LOAD_ANNOTATIONS: dict[str, tuple[str, str]] = {
    # TechEmpower-style simple loads.
    "Plaintext":                 ("/plaintext", "static text"),
    "JSON":                      ("/json",      "static JSON"),
    # TechEmpower-style database loads.
    "Database single query":     ("/db",       "single SELECT"),
    "Database multiple queries": ("/query",    "20 SELECTs"),
    "Database fortunes":         ("/fortunes", "HTML render"),
    "Database updates":          ("/updates",  "20 SELECTs + UPDATE"),
    # Bootgly-internal DB probes (native = wire driver, resource = ADI runner).
    "Database micro query":            ("/database/native/ping",         "native SELECT 1"),
    "Database resource query":         ("/database/resource/ping",       "resource SELECT 1"),
    "Database micro parameterized":    ("/database/native/parameters",   "native bound params"),
    "Database resource parameterized": ("/database/resource/parameters", "resource bound params"),
    "Database micro multi-query":      ("/database/native/pool",         "native pooled queries"),
    "Database resource multi-query":   ("/database/resource/pool",       "resource pooled queries"),
    "Database micro sleep":            ("/database/native/sleep",        "native pg_sleep"),
    "Database resource sleep":         ("/database/resource/sleep",      "resource pg_sleep"),
    # Router-set loads.
    "1 static route":                   ("/",            "single static lookup"),
    "10 static routes":                 ("/static/N",    "10 hot static routes"),
    "100 static routes":                ("/static/N",    "100 static routes"),
    "1 dynamic route":                  ("/d/:p",        "single dynamic param"),
    "10 dynamic routes":                ("/d/:p",        "10 dynamic params"),
    "100 dynamic routes":               ("/d/:p",        "100 dynamic params"),
    "Catch-all 404":                    ("/*",           "catch-all fallback"),
    "6 nested routes (2 groups)":       ("/admin, /account", "2 nested groups"),
    "Mixed (5 static + 3 dynamic)":     ("/mixed-8",     "5 static + 3 dynamic"),
    "Mixed (10 static + 10 dynamic)":   ("/mixed-20",    "10 static + 10 dynamic"),
    "Full mix (all types)":             ("/full",        "static + dynamic + nested"),
}


def _load_title(label: str) -> str:
    info = LOAD_ANNOTATIONS.get(label)
    if info is None:
        return label
    route, descriptor = info
    return f"{route} — {label} ({descriptor})"


# ----------------------------------------------------------------------------
# Parsing
# ----------------------------------------------------------------------------

def parse_marks(path: Path) -> dict:
    """Parse one .marks file into {'benchmark', 'date', 'config', 'results'}."""
    benchmark = ""
    date = ""
    config: dict[str, str] = {}
    results: dict[tuple[str, str], int | None] = {}

    for raw in path.read_text().splitlines():
        line = raw.rstrip()

        if line.startswith("# Benchmark:"):
            benchmark = line.split(":", 1)[1].strip()
            continue
        if line.startswith("# Date:"):
            date = line.split(":", 1)[1].strip()
            continue
        if line.startswith("# Config:"):
            continue

        m = CONFIG_LINE.match(line)
        if m:
            config[m.group(1)] = m.group(2)
            continue

        m = RESULT_LINE.match(line)
        if m:
            competitor = m.group(1)
            load = m.group(2)
            rps: int | None = None
            for k, v in KV.findall(m.group(3)):
                if k == "rps":
                    rps = None if v == "N/A" else int(v.replace(",", "").replace(".", ""))
                    break
            results[(competitor, load)] = rps

    return {"benchmark": benchmark, "date": date, "config": config, "results": results}


def detect_x_key(parsed: list[dict], override: str | None) -> str:
    if override:
        return override
    keys: dict[str, set[str]] = {}
    for p in parsed:
        for k, v in p["config"].items():
            keys.setdefault(k, set()).add(v)
    varying = {k: vals for k, vals in keys.items() if len(vals) > 1}
    if not varying:
        raise SystemExit("No config key varies across the supplied .marks files; pass --x-key.")
    for preferred in X_PREFERENCE:
        if preferred in varying:
            return preferred
    return max(varying, key=lambda k: len(varying[k]))


# ----------------------------------------------------------------------------
# Environment detection
# ----------------------------------------------------------------------------

def detect_environment(competitors: list[str]) -> dict[str, str]:
    """Auto-detect what we can about the host (best-effort, never raises)."""
    env: dict[str, str] = {}

    cpu = os.cpu_count() or 0
    if cpu:
        env["CPU"] = f"{cpu} logical processors"

    try:
        env["OS"] = subprocess.check_output(["uname", "-sr"], text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:  # noqa: BLE001
        env["OS"] = sys.platform

    try:
        php = subprocess.check_output(["php", "-r", "echo PHP_VERSION;"], text=True, stderr=subprocess.DEVNULL).strip()
        if php:
            env["PHP"] = php
    except Exception:  # noqa: BLE001
        pass

    swoole_mentioned = any("swoole" in c.lower() for c in competitors)
    if swoole_mentioned:
        try:
            sw = subprocess.check_output(
                ["php", "-r", "echo extension_loaded('swoole') ? phpversion('swoole') : '';"],
                text=True, stderr=subprocess.DEVNULL,
            ).strip()
            if sw:
                env["Swoole"] = sw
        except Exception:  # noqa: BLE001
            pass

    return env


# ----------------------------------------------------------------------------
# Charts
# ----------------------------------------------------------------------------

def plot_throughput(path: Path, title: str, x_key: str, x_values, loads, competitors, data):
    n = len(loads)
    if n <= 1:
        cols = 1
    elif n <= 4:
        cols = 2
    else:
        cols = 3
    rows = (n + cols - 1) // cols

    fig, axes = plt.subplots(rows, cols, figsize=(6.0 * cols, 4.2 * rows), squeeze=False)
    fig.suptitle(title, fontsize=14, fontweight="bold")

    for i, load in enumerate(loads):
        ax = axes[i // cols][i % cols]
        for j, c in enumerate(competitors):
            ys = [float(v) if v is not None else None for v in data[load][c]]
            color = COMPETITOR_COLORS[j % len(COMPETITOR_COLORS)]
            ax.plot(x_values, ys, marker="o", linewidth=2, color=color, label=c)
        ax.set_title(_load_title(load), fontsize=11)
        ax.set_xlabel(x_key)
        ax.set_ylabel("Requests / second")
        ax.grid(True, alpha=0.3)
        ax.legend(loc="lower right", fontsize=9)
        ax.set_ylim(bottom=0)

    for k in range(n, rows * cols):
        axes[k // cols][k % cols].set_visible(False)

    plt.tight_layout()
    plt.savefig(path, dpi=140, bbox_inches="tight")
    plt.close(fig)


def plot_ratio(path: Path, title: str, x_key: str, x_values, loads, competitors, data, baseline):
    others = [c for c in competitors if c != baseline]
    if not others:
        return

    n = len(loads)
    # Larger figure and external legend when many loads compete for room.
    if n > 6:
        figsize = (14, 7.5)
        legend_kwargs = {"loc": "center left", "bbox_to_anchor": (1.01, 0.5), "fontsize": 9}
    else:
        figsize = (11, 6)
        legend_kwargs = {"loc": "best", "fontsize": 9}

    fig, ax = plt.subplots(figsize=figsize)

    for i, load in enumerate(loads):
        base = data[load][baseline]
        for other in others:
            comp = data[load][other]
            ratios: list[float | None] = []
            for b, o in zip(base, comp):
                if b is None or o is None or o == 0:
                    ratios.append(None)
                else:
                    ratios.append(b / o)
            color = LOAD_COLORS[i % len(LOAD_COLORS)]
            label = load if len(others) == 1 else f"{load} ({other})"
            ax.plot(x_values, ratios, marker="o", linewidth=2, color=color, label=label)

    ax.axhline(1.0, color="#6b7280", linewidth=1, linestyle="--", label="Parity (1.0)")
    ax.set_title(f"{baseline} ratio vs {'/'.join(others)} — {title}", fontsize=13, fontweight="bold")
    ax.set_xlabel(x_key)
    ax.set_ylabel(f"{baseline} req/s ÷ competitor req/s")
    ax.grid(True, alpha=0.3)
    ax.legend(**legend_kwargs)
    ax.set_ylim(bottom=0)

    plt.tight_layout()
    plt.savefig(path, dpi=140, bbox_inches="tight")
    plt.close(fig)


# ----------------------------------------------------------------------------
# Report
# ----------------------------------------------------------------------------

def _fmt(n: int | None) -> str:
    if n is None:
        return "N/A"
    return f"{n:,}".replace(",", ".")


def _delta(base: int | None, other: int | None) -> str:
    if base is None or other is None or other == 0:
        return "—"
    pct = ((base - other) / other) * 100.0
    sign = "+" if pct >= 0 else ""
    return f"{sign}{pct:.1f}%"


def _competitor_slug(name: str) -> str:
    return re.sub(r"[\s_]+", "-", name.strip().lower())


def _fmt_x(v) -> str:
    if isinstance(v, float) and v.is_integer():
        return str(int(v))
    return str(v)


def _reproduction_command(case: str, load_set: str, x_key: str, x_values, shared: dict[str, str], competitors: list[str], loads: list[str]) -> str:
    """Best-effort `for`-loop reproducing the sweep."""
    env_pairs: list[str] = []

    # # Case-specific env vars (currently only HTTP_Server_CLI's load set).
    if case == "HTTP_Server_CLI" and load_set and load_set != "router":
        env_pairs.append(f"BOOTGLY_HTTP_SERVER_CLI_ROUTER={load_set}")
        env_pairs.append(f"BOOTGLY_HTTP_SERVER_CLI_LOADS={load_set}")

    flag_lines: list[str] = []
    if "runner" in shared:
        flag_lines.append(f"--runner={shared['runner']}")
    if "connections" in shared:
        flag_lines.append(f"--connections={shared['connections']}")
    if "duration" in shared:
        flag_lines.append(f"--duration={shared['duration']}")
    if "client-workers" in shared:
        flag_lines.append(f"--client-workers={shared['client-workers']}")
    if "pipeline" in shared and shared["pipeline"] != "1":
        flag_lines.append(f"--pipeline={shared['pipeline']}")

    # # X-axis variable.
    if x_key == "server-workers":
        loop_var = "sw"
    elif x_key == "client-workers":
        loop_var = "cw"
    else:
        loop_var = "x"
    flag_lines.append(f'--{x_key}="${loop_var}"')

    competitors_csv = ",".join(_competitor_slug(c) for c in competitors)
    x_range = " ".join(_fmt_x(v) for v in x_values)

    lines = ["```bash", f"for {loop_var} in {x_range}; do"]

    if env_pairs:
        first, *rest = env_pairs
        lines.append(f"   env {first} \\")
        for ev in rest:
            lines.append(f"       {ev} \\")

    lines.append(f"   php bootgly test benchmark {case} \\")
    lines.append(f"      --competitors={competitors_csv} \\")
    for fl in flag_lines[:-1]:
        lines.append(f"      {fl} \\")
    lines.append(f"      {flag_lines[-1]} \\")
    lines.append(f"      --loads=<IDS>  # loads in this sweep: {', '.join(loads)}")
    lines.append("done")
    lines.append("```")

    return "\n".join(lines)


def write_report(
    path: Path,
    chart_throughput_name: str,
    chart_ratio_name: str,
    case: str,
    load_set: str,
    title: str,
    x_key: str,
    x_values,
    loads,
    competitors,
    data,
    baseline,
    marks_paths,
    parsed,
):
    others = [c for c in competitors if c != baseline]

    # Shared config (constant across the sweep).
    shared: dict[str, str] = {}
    if parsed:
        for k, v in parsed[0]["config"].items():
            if k == x_key:
                continue
            if all(p["config"].get(k) == v for p in parsed):
                shared[k] = v

    env = detect_environment(competitors)

    # N/A detection for notes
    any_na = any(
        v is None
        for s in loads
        for c in competitors
        for v in data[s][c]
    )

    lines: list[str] = []
    lines.append(f"# {title}")
    lines.append("")
    lines.append(f"`{case}` benchmark — sweep of {len(marks_paths)} `.bench.marks` files")
    lines.append(f"varying `{x_key}` from `{_fmt_x(x_values[0])}` to `{_fmt_x(x_values[-1])}`, load set")
    lines.append(f"`{load_set}`. Generated by `chart.py` on `{datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}`.")
    lines.append("")

    # ## Environment
    lines.append("## Environment")
    lines.append("")
    for label in ("OS", "CPU", "PHP", "Swoole"):
        if label in env:
            lines.append(f"- **{label}** — {env[label]}")
    for label, key in (
        ("Runner", "runner"),
        ("Load set", "load-set"),
        ("Connections", "connections"),
        ("Duration", "duration"),
        ("Client workers", "client-workers"),
        ("Pipeline", "pipeline"),
    ):
        if key in shared:
            lines.append(f"- **{label}** — `{shared[key]}`")
    lines.append("")

    # ## Command
    lines.append("## Command")
    lines.append("")
    lines.append("Reproduction sweep — replace `<IDS>` with the original `--loads=` argument:")
    lines.append("")
    lines.append(_reproduction_command(case, load_set, x_key, x_values, shared, competitors, loads))
    lines.append("")

    # ## Throughput
    lines.append("## Throughput")
    lines.append("")
    lines.append(f"![Throughput chart]({chart_throughput_name})")
    lines.append("")

    # ## Ratio
    if others:
        lines.append(f"## {baseline} / competitor ratio")
        lines.append("")
        lines.append(f"![Ratio chart]({chart_ratio_name})")
        lines.append("")
        lines.append(f"Ratio > 1.0 means **{baseline}** is faster than the competitor at that {x_key}.")
        lines.append("")

    # ## Comparison tables
    lines.append("## Comparison tables")
    lines.append("")
    for load in loads:
        lines.append(f"### {load}")
        lines.append("")
        header = [f"`{x_key}`"] + competitors[:]
        if others:
            header.append(f"Δ ({baseline} vs {others[0]})")
        lines.append("| " + " | ".join(header) + " |")
        lines.append("|" + "|".join(["---:" for _ in header]) + "|")
        for i, x in enumerate(x_values):
            row = [str(int(x)) if isinstance(x, float) and x.is_integer() else str(x)]
            for c in competitors:
                row.append(_fmt(data[load][c][i]))
            if others:
                row.append(_delta(data[load][baseline][i], data[load][others[0]][i]))
            lines.append("| " + " | ".join(row) + " |")
        lines.append("")

    # ## Peaks
    lines.append("## Peaks")
    lines.append("")
    head = ["Load"] + [f"{c} peak (req/s @ {x_key})" for c in competitors]
    if others:
        head.append(f"Δ at {baseline} peak")
    lines.append("| " + " | ".join(head) + " |")
    lines.append("|" + "|".join(["---" for _ in head]) + "|")
    for load in loads:
        row = [load]
        peak_indices: dict[str, int] = {}
        for c in competitors:
            series = [v if v is not None else 0 for v in data[load][c]]
            i = series.index(max(series))
            peak_indices[c] = i
            x = x_values[i]
            x_str = str(int(x)) if isinstance(x, float) and x.is_integer() else str(x)
            row.append(f"{_fmt(series[i])} @ {x_str}")
        if others:
            bi = peak_indices[baseline]
            row.append(_delta(data[load][baseline][bi], data[load][others[0]][bi]))
        lines.append("| " + " | ".join(row) + " |")
    lines.append("")

    # ## Notes
    lines.append("## Notes")
    lines.append("")
    if any_na:
        lines.append(
            "- One or more cells are `N/A`. The Bootgly benchmark runner emits"
            " `N/A` when a preflight check times out (default 3 s) on the very"
            " first request of a cold worker; the immediately next run of the"
            " same load usually succeeds. Consider re-running just those"
            " cells in isolation."
        )
    try:
        cpu_n = int((env.get("CPU") or "0").split()[0])
    except (ValueError, IndexError):
        cpu_n = 0
    try:
        client_workers = int(shared.get("client-workers", "0"))
    except ValueError:
        client_workers = 0
    if cpu_n and client_workers:
        max_sw = max(int(v) for v in x_values) if x_key == "server-workers" else 0
        if max_sw and max_sw + client_workers > cpu_n:
            lines.append(
                f"- The sweep crosses the CPU oversubscription threshold —"
                f" `server-workers + client-workers > {cpu_n}` logical processors."
                f" Above that point the kernel scheduler and external services"
                f" (e.g. PostgreSQL) become the bottleneck, not the framework."
            )
    lines.append(
        "- Files consumed: "
        + ", ".join(f"`{p.name}`" for p in marks_paths[:3])
        + (f" … (+{len(marks_paths) - 3} more)" if len(marks_paths) > 3 else "")
    )
    lines.append("")

    path.write_text("\n".join(lines))


# ----------------------------------------------------------------------------
# Main
# ----------------------------------------------------------------------------

def main() -> int:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    ap.add_argument("--marks", required=True, help="Glob of .marks files (quote to prevent shell expansion).")
    ap.add_argument("--out", required=True, help="Output directory (created if missing).")
    ap.add_argument("--baseline", default=None, help="Baseline competitor for ratio chart and Δ column (default: first alphabetical).")
    ap.add_argument("--x-key", dest="x_key", default=None, help="Force X axis to this config key.")
    ap.add_argument("--load-set", dest="load_set", default=None, help="Override load-set token used in output filenames (default: from marks Config or 'default').")
    ap.add_argument("--title", default=None, help="Optional report title (defaults to case + sweep summary).")

    args = ap.parse_args()

    marks_paths = sorted(Path(p) for p in glob.glob(args.marks))
    if not marks_paths:
        raise SystemExit(f"No files matched: {args.marks}")

    parsed = [parse_marks(p) for p in marks_paths]

    # Tag every parsed entry with its source path so we can re-derive
    # marks_paths after sorting by the x-axis value.
    for path, p in zip(marks_paths, parsed):
        p["__source__"] = path

    missing = [str(p["__source__"]) for p in parsed if not p["config"]]
    if missing:
        raise SystemExit(
            "These .marks files have no `# Config:` header (need Bootgly v0.16+):\n  "
            + "\n  ".join(missing)
        )

    x_key = detect_x_key(parsed, args.x_key)

    def _x(p: dict):
        v = p["config"].get(x_key, "")
        try:
            return float(v)
        except ValueError:
            return v

    parsed.sort(key=_x)
    marks_paths = [p["__source__"] for p in parsed]
    x_values: list = [_x(p) for p in parsed]

    competitors: list[str] = []
    loads: list[str] = []
    for p in parsed:
        for (c, s) in p["results"]:
            if c not in competitors:
                competitors.append(c)
            if s not in loads:
                loads.append(s)

    data: dict[str, dict[str, list[int | None]]] = {
        s: {c: [] for c in competitors} for s in loads
    }
    for p in parsed:
        for s in loads:
            for c in competitors:
                data[s][c].append(p["results"].get((c, s)))

    baseline = args.baseline or sorted(competitors)[0]
    if baseline not in competitors:
        raise SystemExit(f"--baseline '{baseline}' not present in results. Available: {competitors}")

    case = parsed[0]["benchmark"] or "Benchmark"
    load_set = (
        args.load_set
        or parsed[0]["config"].get("load-set")
        or "default"
    )
    timestamp = datetime.datetime.now().strftime("%Y-%m-%d_%H%M%S")
    base_name = f"RESULTS-{load_set}-{timestamp}"

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)

    chart_throughput_name = f"{base_name}.chart.throughput.png"
    chart_ratio_name = f"{base_name}.chart.ratio.png"
    report_name = f"{base_name}.md"

    title = args.title or f"{case} — {load_set} sweep over {x_key}"

    plot_throughput(out_dir / chart_throughput_name, title, x_key, x_values, loads, competitors, data)
    plot_ratio(out_dir / chart_ratio_name, title, x_key, x_values, loads, competitors, data, baseline)
    write_report(
        out_dir / report_name,
        chart_throughput_name,
        chart_ratio_name,
        case,
        load_set,
        title,
        x_key,
        x_values,
        loads,
        competitors,
        data,
        baseline,
        marks_paths,
        parsed,
    )

    print(f"OK — {len(parsed)} marks files, x_key={x_key}, baseline={baseline}")
    print(f"  {out_dir / report_name}")
    print(f"  {out_dir / chart_throughput_name}")
    print(f"  {out_dir / chart_ratio_name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
