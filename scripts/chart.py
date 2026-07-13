#!/usr/bin/env python3
"""Generate trend report and charts from a range of Bootgly .bench.marks files.

Parses .marks files (with the `# Config:` header introduced in Bootgly
v0.16-beta), groups results by opponent + load, and renders four files
keyed by the load set and current timestamp:

    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.md
    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.throughput.png
    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.ratio.png
    RESULTS-<load-set>-<YYYY-MM-DD_HHMMSS>.chart.latency.png

The X axis is auto-detected: among the config keys present in every input file
(`server-workers`, `client-workers`, `connections`, `duration`, `pipeline`),
the one with the most distinct values is picked. Pass `--x-key NAME` to
override. Files that lack a `# Config:` header are rejected (use Bootgly
v0.16-beta or newer).

The load-set token comes from the marks `# Config: load-set` key
(case files surface it via `$Runner->meta`). Pass `--load-set NAME` to
override; defaults to `default` if neither marks nor CLI supplies one.

Usage:
    chart.py --marks 'storage/.../*.marks' --out OUTPUT_DIR \\
             [--baseline OPPONENT] [--x-key KEY] [--load-set NAME] \\
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
from matplotlib.ticker import FuncFormatter

# Integer tick labels with thousands separators (no decimals / no 1e6 offset).
_INT_TICKS = FuncFormatter(lambda v, _: f"{int(round(v)):,}")

# Latency tick labels: integer-with-separators at/above 1 ms, compact decimals below
# (so sub-millisecond decades like 0.1 / 0.5 keep their value instead of rounding to 0).
_LAT_TICKS = FuncFormatter(
    lambda v, _: "" if v <= 0 else (f"{v:,.0f}" if v >= 1 else f"{v:g}")
)

CONFIG_LINE = re.compile(r"^#\s{3}([a-zA-Z][\w-]*):\s*(.+?)\s*$")
RESULT_LINE = re.compile(r"^\[([^\]]+)\]\[([^\]]+)\]\s*(.*)$")
KV = re.compile(r"(\w+)=([^\s]+)")

# Source identity describes an observation; it is not a tunable benchmark axis.
# Keep it out of automatic X-axis discovery while still allowing an explicit
# `--x-key framework-sha` for a deliberate cross-revision comparison.
PROVENANCE_KEYS = (
    "framework-version",
    "framework-sha",
    "framework-dirty",
    "benchmarks-sha",
    "benchmarks-dirty",
)

# Preferred X axis when multiple config keys vary.
X_PREFERENCE = (
    "server-workers",
    "client-workers",
    "connections",
    "duration",
    "pipeline",
)

# Color palette for opponents and loads.
OPPONENT_COLORS = ["#3b82f6", "#f97316", "#10b981", "#a855f7", "#ef4444", "#0ea5e9"]
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
    "Cached queries":            ("/cached-queries", "20 cached rows"),
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
    # Raw socket loads (TCP_Server_CLI / UDP_Server_CLI).
    "Echo 32 bytes":             ("echo",  "32B round-trip"),
    "HTTP raw (Hello World)":    ("GET /", "fixed 200 OK, no routing"),
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

def _parse_latency_ms(token: str) -> float | None:
    """Normalize a latency token to milliseconds.

    Accepts microseconds (`us` / `µs`), milliseconds (`ms`) or seconds (`s`)
    and returns the value in ms (us -> /1000, ms -> as-is, s -> *1000). Commas
    are stripped as thousands separators; the dot stays the decimal point.
    Returns None for `N/A` or anything that does not parse.
    """
    if token == "N/A":
        return None
    m = re.match(r"^([\d.,]+)(µs|us|ms|s)$", token)
    if m is None:
        return None
    try:
        value = float(m.group(1).replace(",", ""))
    except ValueError:
        return None
    unit = m.group(2)
    if unit in ("us", "µs"):
        return value / 1000.0
    if unit == "s":
        return value * 1000.0
    return value  # already in milliseconds


def parse_marks(path: Path) -> dict:
    """Parse one .marks file into {'benchmark', 'date', 'config', 'results', 'latencies'}."""
    benchmark = ""
    date = ""
    config: dict[str, str] = {}
    results: dict[tuple[str, str], int | None] = {}
    latencies: dict[tuple[str, str], float | None] = {}

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
            opponent = m.group(1)
            load = m.group(2)
            rps: int | None = None
            for k, v in KV.findall(m.group(3)):
                if k == "rps":
                    rps = None if v == "N/A" else int(v.replace(",", "").replace(".", ""))
                    break
            latency_ms: float | None = None
            for k, v in KV.findall(m.group(3)):
                if k == "latency":
                    latency_ms = _parse_latency_ms(v)
                    break
            results[(opponent, load)] = rps
            latencies[(opponent, load)] = latency_ms

    return {"benchmark": benchmark, "date": date, "config": config, "results": results, "latencies": latencies}


def detect_x_key(parsed: list[dict], override: str | None) -> str:
    if override:
        return override
    keys: dict[str, set[str]] = {}
    for p in parsed:
        for k, v in p["config"].items():
            if k in PROVENANCE_KEYS:
                continue
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

def detect_environment(opponents: list[str]) -> dict[str, str]:
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

    swoole_mentioned = any("swoole" in c.lower() for c in opponents)
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

def _render_titles(fig, title: str, subtitle: str) -> None:
    """Bold suptitle plus an optional dimmer info line below it, with a gap."""
    fig.suptitle(title, fontsize=14, fontweight="bold", y=0.985)
    if subtitle:
        fig.text(0.5, 0.915, subtitle, ha="center", va="top", fontsize=10, color="#555555")


def _build_subtitle(loads, shared, x_key) -> str:
    """Info line: the exact load name (when a single load) followed by the
    constant sweep config, excluding the swept (X) axis."""
    bits = []
    if len(loads) == 1:
        bits.append(loads[0])
    for key, fmt in (
        ("connections", "{} connections"),
        ("server-workers", "{} server-workers"),
        ("client-workers", "{} client-workers"),
        ("duration", "{} s"),
    ):
        if key != x_key and key in shared:
            bits.append(fmt.format(shared[key]))
    return " · ".join(bits)


def plot_throughput(path: Path, title: str, subtitle: str, x_key: str, x_values, loads, opponents, data, yscale="linear"):
    n = len(loads)
    if n <= 1:
        cols = 1
    elif n <= 4:
        cols = 2
    else:
        cols = 3
    rows = (n + cols - 1) // cols

    fig, axes = plt.subplots(rows, cols, figsize=(6.0 * cols, 4.2 * rows), squeeze=False)
    _render_titles(fig, title, subtitle)

    for i, load in enumerate(loads):
        ax = axes[i // cols][i % cols]
        for j, c in enumerate(opponents):
            ys = [float(v) if v is not None else None for v in data[load][c]]
            color = OPPONENT_COLORS[j % len(OPPONENT_COLORS)]
            ax.plot(x_values, ys, marker="o", linewidth=2, color=color, label=c)
        ax.set_title(_load_title(load), fontsize=11)
        ax.set_xlabel(x_key)
        ax.set_ylabel("Requests / second")
        ax.grid(True, alpha=0.3)
        ax.legend(loc="lower right", fontsize=9)
        if yscale == "log":
            ax.set_yscale("log")
            # Decade labels as integers (10,000 / 100,000) instead of 10^n.
            ax.yaxis.set_major_formatter(_INT_TICKS)
            ax.yaxis.set_minor_formatter(plt.NullFormatter())
        else:
            # Headroom above the tallest point so its marker is not clipped by
            # the top spine.
            ymax = max(
                (float(v) for c in opponents for v in data[load][c] if v is not None),
                default=0.0,
            )
            ax.set_ylim(bottom=0, top=ymax * 1.08 if ymax > 0 else None)
            # Plain integer ticks (e.g. 1,000,000) instead of 0.2 … ×1e6.
            ax.ticklabel_format(style="plain", axis="y")
            ax.yaxis.set_major_formatter(_INT_TICKS)

    for k in range(n, rows * cols):
        axes[k // cols][k % cols].set_visible(False)

    plt.tight_layout(rect=(0, 0, 1, 0.87) if subtitle else (0, 0, 1, 1))
    plt.savefig(path, dpi=140, bbox_inches="tight")
    plt.close(fig)


def plot_latency(path: Path, title: str, subtitle: str, x_key: str, x_values, loads, opponents, data_latency, yscale="log"):
    n = len(loads)
    if n <= 1:
        cols = 1
    elif n <= 4:
        cols = 2
    else:
        cols = 3
    rows = (n + cols - 1) // cols

    fig, axes = plt.subplots(rows, cols, figsize=(6.0 * cols, 4.2 * rows), squeeze=False)
    _render_titles(fig, title, subtitle)

    for i, load in enumerate(loads):
        ax = axes[i // cols][i % cols]
        for j, c in enumerate(opponents):
            ys = [float(v) if v is not None else None for v in data_latency[load][c]]
            color = OPPONENT_COLORS[j % len(OPPONENT_COLORS)]
            ax.plot(x_values, ys, marker="o", linewidth=2, color=color, label=c)
        ax.set_title(_load_title(load), fontsize=11)
        ax.set_xlabel(x_key)
        ax.set_ylabel("Latency (ms) — lower is better")
        ax.grid(True, alpha=0.3)
        # Lower is better -> fast runs sit near the bottom; keep the legend up top.
        ax.legend(loc="upper right", fontsize=9)
        if yscale == "log":
            ax.set_yscale("log")
            # Decade labels as plain numbers (0.1 / 1 / 10 / 100 / 1,000), never 0.
            ax.yaxis.set_major_formatter(_LAT_TICKS)
            ax.yaxis.set_minor_formatter(plt.NullFormatter())
        else:
            ax.set_ylim(bottom=0)
            ax.ticklabel_format(style="plain", axis="y")
            ax.yaxis.set_major_formatter(_LAT_TICKS)

    for k in range(n, rows * cols):
        axes[k // cols][k % cols].set_visible(False)

    plt.tight_layout(rect=(0, 0, 1, 0.87) if subtitle else (0, 0, 1, 1))
    plt.savefig(path, dpi=140, bbox_inches="tight")
    plt.close(fig)


def plot_ratio(path: Path, title: str, x_key: str, x_values, loads, opponents, data, baseline):
    others = [c for c in opponents if c != baseline]
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
    ax.set_ylabel(f"{baseline} req/s ÷ opponent req/s")
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


def _opponent_slug(name: str) -> str:
    return re.sub(r"[\s_]+", "-", name.strip().lower())


def _fmt_x(v) -> str:
    if isinstance(v, float) and v.is_integer():
        return str(int(v))
    return str(v)


def _reproduction_command(case: str, load_set: str, x_key: str, x_values, shared: dict[str, str], opponents: list[str], loads: list[str]) -> str:
    """Best-effort `for`-loop reproducing the sweep."""
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

    opponents_csv = ",".join(_opponent_slug(c) for c in opponents)
    x_range = " ".join(_fmt_x(v) for v in x_values)

    lines = ["```bash", f"for {loop_var} in {x_range}; do"]

    lines.append(f"   php bootgly test benchmark {case} \\")
    lines.append(f"      --opponents={opponents_csv} \\")
    for fl in flag_lines[:-1]:
        lines.append(f"      {fl} \\")
    lines.append(f"      {flag_lines[-1]} \\")
    loads_arg = f"{load_set}:<IDS>" if load_set and load_set != "default" else "<IDS>"
    lines.append(f"      --loads={loads_arg}  # loads in this sweep: {', '.join(loads)}")
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
    opponents,
    data,
    baseline,
    marks_paths,
    parsed,
):
    others = [c for c in opponents if c != baseline]

    # Shared config (constant across the sweep).
    shared: dict[str, str] = {}
    if parsed:
        for k, v in parsed[0]["config"].items():
            if k == x_key:
                continue
            if all(p["config"].get(k) == v for p in parsed):
                shared[k] = v

    env = detect_environment(opponents)

    # N/A detection for notes
    any_na = any(
        v is None
        for s in loads
        for c in opponents
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
        ("Framework version", "framework-version"),
        ("Framework SHA", "framework-sha"),
        ("Framework dirty", "framework-dirty"),
        ("Benchmarks SHA", "benchmarks-sha"),
        ("Benchmarks dirty", "benchmarks-dirty"),
        ("Runner", "runner"),
        ("Load set", "load-set"),
        ("Connections", "connections"),
        ("Duration", "duration"),
        ("Server workers", "server-workers"),
        ("Client workers", "client-workers"),
        ("Pipeline", "pipeline"),
        ("DB pool max", "db-pool-max"),
    ):
        if key in shared:
            lines.append(f"- **{label}** — `{shared[key]}`")
    if "db-pool-max" in shared:
        pool = shared["db-pool-max"]
        pooled = [c for c in opponents if "laravel" not in c.lower()]
        laravel = [c for c in opponents if "laravel" in c.lower()]
        note = (
            f"> **Equal per-worker DB connection — pool = `{pool}` for every framework.** "
            f"{', '.join(pooled)} inherit `DB_POOL_MAX={pool}` from the runner environment, so each "
            f"worker holds at most {pool} PostgreSQL connection(s)."
        )
        if laravel:
            note += (
                f" {', '.join(laravel)} runs PHP-FPM with `pm.max_children = server-workers`, so each "
                "FPM child also opens exactly one connection — matching the pooled servers' "
                "per-worker footprint."
            )
        note += (
            f" Every opponent therefore presents the same database footprint at each point "
            f"(`server-workers` connections total), so no framework gets a connection-count advantage."
        )
        lines.append("")
        lines.append(note)
    lines.append("")

    # ## Command
    lines.append("## Command")
    lines.append("")
    lines.append("Reproduction sweep — replace `<IDS>` with the original `--loads=` argument:")
    lines.append("")
    lines.append(_reproduction_command(case, load_set, x_key, x_values, shared, opponents, loads))
    lines.append("")

    # ## Throughput
    lines.append("## Throughput")
    lines.append("")
    lines.append(f"![Throughput chart]({chart_throughput_name})")
    lines.append("")

    # ## Ratio
    if others:
        lines.append(f"## {baseline} / opponent ratio")
        lines.append("")
        lines.append(f"![Ratio chart]({chart_ratio_name})")
        lines.append("")
        lines.append(f"Ratio > 1.0 means **{baseline}** is faster than the opponent at that {x_key}.")
        lines.append("")

    # ## Comparison tables
    lines.append("## Comparison tables")
    lines.append("")
    for load in loads:
        lines.append(f"### {load}")
        lines.append("")
        header = [f"`{x_key}`"] + opponents[:]
        if others:
            header.append(f"Δ ({baseline} vs {others[0]})")
        lines.append("| " + " | ".join(header) + " |")
        lines.append("|" + "|".join(["---:" for _ in header]) + "|")
        for i, x in enumerate(x_values):
            row = [str(int(x)) if isinstance(x, float) and x.is_integer() else str(x)]
            for c in opponents:
                row.append(_fmt(data[load][c][i]))
            if others:
                row.append(_delta(data[load][baseline][i], data[load][others[0]][i]))
            lines.append("| " + " | ".join(row) + " |")
        lines.append("")

    # ## Peaks
    lines.append("## Peaks")
    lines.append("")
    head = ["Load"] + [f"{c} peak (req/s @ {x_key})" for c in opponents]
    if others:
        head.append(f"Δ at {baseline} peak")
    lines.append("| " + " | ".join(head) + " |")
    lines.append("|" + "|".join(["---" for _ in head]) + "|")
    for load in loads:
        row = [load]
        peak_indices: dict[str, int] = {}
        for c in opponents:
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
    mixed_provenance: list[str] = []
    for key in PROVENANCE_KEYS:
        values = {p["config"].get(key, "<missing>") for p in parsed}
        if len(values) > 1:
            mixed_provenance.append(f"`{key}` = " + ", ".join(f"`{v}`" for v in sorted(values)))
    if mixed_provenance:
        lines.append(
            "- **Mixed source provenance:** "
            + "; ".join(mixed_provenance)
            + ". Treat the combined series as a cross-source comparison."
        )
    dirty_sources = [
        label
        for label, key in (("framework", "framework-dirty"), ("benchmark suite", "benchmarks-dirty"))
        if shared.get(key) == "true"
    ]
    if dirty_sources:
        lines.append(
            "- **Dirty source tree:** "
            + " and ".join(dirty_sources)
            + " contained uncommitted or untracked changes when the benchmark started."
        )
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
    ap.add_argument("--baseline", default=None, help="Baseline opponent for ratio chart and Δ column (default: first alphabetical).")
    ap.add_argument("--x-key", dest="x_key", default=None, help="Force X axis to this config key.")
    ap.add_argument("--load-set", dest="load_set", default=None, help="Override load-set token used in output filenames (default: from marks Config or 'default').")
    ap.add_argument("--title", default=None, help="Optional report title (defaults to case + sweep summary).")
    ap.add_argument("--yscale", default="linear", choices=["linear", "log"], help="Throughput Y axis scale (default: linear). Use 'log' when opponents differ by orders of magnitude.")

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

    opponents: list[str] = []
    loads: list[str] = []
    for p in parsed:
        for (c, s) in p["results"]:
            if c not in opponents:
                opponents.append(c)
            if s not in loads:
                loads.append(s)

    data: dict[str, dict[str, list[int | None]]] = {
        s: {c: [] for c in opponents} for s in loads
    }
    for p in parsed:
        for s in loads:
            for c in opponents:
                data[s][c].append(p["results"].get((c, s)))

    data_latency: dict[str, dict[str, list[float | None]]] = {
        s: {c: [] for c in opponents} for s in loads
    }
    for p in parsed:
        for s in loads:
            for c in opponents:
                data_latency[s][c].append(p["latencies"].get((c, s)))

    baseline = args.baseline or sorted(opponents)[0]
    if baseline not in opponents:
        raise SystemExit(f"--baseline '{baseline}' not present in results. Available: {opponents}")

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
    chart_latency_name = f"{base_name}.chart.latency.png"
    report_name = f"{base_name}.md"

    title = args.title or f"{case} — {load_set} sweep over {x_key}"
    shared = {
        k: v
        for k, v in (parsed[0]["config"].items() if parsed else [])
        if k != x_key and all(p["config"].get(k) == v for p in parsed)
    }
    subtitle = _build_subtitle(loads, shared, x_key)

    plot_throughput(out_dir / chart_throughput_name, title, subtitle, x_key, x_values, loads, opponents, data, yscale=args.yscale)
    plot_ratio(out_dir / chart_ratio_name, title, x_key, x_values, loads, opponents, data, baseline)
    plot_latency(out_dir / chart_latency_name, f"{title} — latency", subtitle, x_key, x_values, loads, opponents, data_latency, yscale="log")
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
        opponents,
        data,
        baseline,
        marks_paths,
        parsed,
    )

    print(f"OK — {len(parsed)} marks files, x_key={x_key}, baseline={baseline}")
    print(f"  {out_dir / report_name}")
    print(f"  {out_dir / chart_throughput_name}")
    print(f"  {out_dir / chart_ratio_name}")
    print(f"  {out_dir / chart_latency_name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
