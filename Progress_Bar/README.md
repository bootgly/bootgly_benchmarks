# ⏱️ Benchmark - Progress Bar

This document contains informations about benchmarking the **Progress Bar** component, a well-known component for the CLI.

| ![](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/artifacts/bootgly/bootgly-progress_bar-benchmark.1.png "Render 6x faster than Symfony / Laravel") |
|:--:| 
| *Progress component (with Bar) - Render ≈ 7x faster than Symfony / Laravel* |

## � Quick Start

```bash
# Clone both repositories side by side
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git

# Run the benchmark
cd bootgly
./bootgly test benchmark Progress_Bar
```

This case uses the **Code** runner, which executes each competitor as a subprocess and measures time and memory.

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--competitors=NAME,...` | all | Filter competitors (`bootgly`, `laravel`) |
| `--iterations=N` | `1` | Number of iterations per competitor |
| `--timeout=N` | `120` | Timeout in seconds per execution |
| `--warmup=N` | `0` | Warmup iterations (discarded) |

### Example

```bash
./bootgly test benchmark Progress_Bar --competitors=bootgly,laravel --iterations=3
```

### Help

```bash
./bootgly test benchmark Progress_Bar --help
```

---

##  Context

The context of this benchmark is framework performance only, more specifically how fast the CLI component can render units of progress in a while with a fixed # of loops.

- No application processing will be executed within the loop.
- Settings that limit rendering will also be removed for maximum performance.

**Interface:**

CLI

**Platform:**

-- None --

**Workable:**

-- None --

---

##  Results

> Bootgly Progress Bar is ≈ 7x faster than Laravel / Symfony Progress Bar

Framework | Result | Position
--- | --- | ---
Bootgly | 6.49s | 🥇 First (winner)
Laravel/Symfony | 45s | 🥈 Second

### Bootgly Progress Bar

![bootgly-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/artifacts/bootgly/bootgly-progress_bar-benchmark.1.png)

---

### Laravel/Symfony Progress Bar

![laravel-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/raw/main/Progress_Bar/artifacts/laravel/laravel-progress_bar-benchmark.1.png)
