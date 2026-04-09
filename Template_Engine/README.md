# ⏱️ Benchmark - Template Engine (`foreach` directive)

In this benchmark, we will compare the performance of the `foreach` directive in different template engines. The `foreach` directive is commonly used to iterate over a collection of data and generate dynamic content.

## � Quick Start

```bash
# Clone both repositories side by side
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git

# Install Laravel Blade dependency
cd bootgly_benchmarks/Template_Engine/artifacts/laravel
composer install
cd ../../../..

# Run the benchmark
cd bootgly
./bootgly test benchmark Template_Engine
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
./bootgly test benchmark Template_Engine --competitors=bootgly,laravel --iterations=3
```

### Help

```bash
./bootgly test benchmark Template_Engine --help
```

---

## �🔍 Context

The goal is to determine which template engine performs the best in terms of speed and efficiency when iterating over a large collection of data.

We will compare the performance of the `foreach` directive in the following template engines:

- Bootgly Template Engine
- Blade

The benchmark will focus on the rendering time of each template engine and will exclude any application processing within the loop.

The benchmark will be executed through a `CLI` interface.

The test involves iterating over a collection of `1,000,000 items`.

The start time in microtime is recorded just before the `foreach` directive begins iterating over the collection, and the end time is recorded immediately after the `foreach` directive finishes iterating. This approach ensures that other factors do not affect the accuracy of the results.

The total time taken is then calculated and displayed on the terminal.

It's important to note that the Bootgly template engine does not sacrifice any of the features available in the Blade's foreach directive. These features include:

- Access to the loop variable for additional information (like `loop->index`, `loop->count`, `loop->first`, `loop->last`, etc.)
- Ability to use `@continue` and `@break` directives within the loop

---

**Interface:**

ABI

**Platform:**

-- None --

**Workable:**

-- None --

---


## 📊 Results

> Bootgly Template Engine is ≈ 9x faster than Laravel Blade (without sacrificing features)

Framework | Result | Position
--- | --- | ---
Bootgly | 0.046s | 🥇 First (winner)
Laravel | 0.438s | 🥈 Second

---

By comparing the times taken by the foreach directive in the Bootgly Template Engine and Blade, we can gain insights into the relative performance of these two template engines when handling large collections of data. This information can be useful when choosing a template engine for projects that involve processing large amounts of data.

> [!WARNING]
> It's important to note that the exact numbers in the benchmark results may vary depending on the specific environment and resources where the benchmark is being run. Factors such as the hardware specifications, the operating system, the PHP version, and other running processes can all influence the performance of the template engines.
> 
> However, while the exact numbers may vary, the relative performance or the proportion between the results should remain consistent. That is, if the Bootgly Template Engine is faster than Laravel Blade in one environment, it should be faster in other environments as well, even if the exact speedup factor varies. This consistency in relative performance makes the benchmark a useful tool for comparing the efficiency of different template engines, regardless of the specific environment where they are run.
