<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/bootgly-logo.128x128.jpg" alt="bootgly-logo" width="120px" height="120px"/>
</p>
<h1 align="center">Bootgly_Benchmarks ⏱️</h1>
<p align="center">
  <i>Benchmarks comparing Bootgly with alternatives.</i>
</p>
<p align="center">
  <a href="https://packagist.org/packages/bootgly/bootgly">
    <img alt="Bootgly License" src="https://img.shields.io/github/license/bootgly/bootgly"/>
  </a>
</p>

## 📊 Benchmark Cases

Benchmarks | Interface | Result
--- | --- | ---
[Progress Bar][BENCHMARK_01] | CLI | ≈ 7x faster than Laravel / Symfony Progress Bar to render 250k iterations
[Template Engine - foreach][BENCHMARK_02] | ABI | ≈ 9x faster than Laravel Blade (without sacrificing features)
[HTTP Server CLI (Router)][BENCHMARK_03] | WPI | Router benchmark: 100 static + 100 dynamic + 6 nested + 3 middleware + catch-all vs 8 competitors

<!-- Links -->
[BENCHMARK_01]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Progress_Bar/README.md
[BENCHMARK_02]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Template_Engine/README.md
[BENCHMARK_03]: https://github.com/bootgly/bootgly_benchmarks/tree/main/HTTP_Server_CLI/README.md

---

## 🚀 Getting Started

### 1. Clone both repositories side by side

```bash
git clone https://github.com/bootgly/bootgly.git
git clone https://github.com/bootgly/bootgly_benchmarks.git
```

Expected directory layout:

```
parent/
├── bootgly/
└── bootgly_benchmarks/
```

### 2. Run a benchmark

```bash
cd bootgly
./bootgly test benchmark <CASE>
```

Available cases:

```bash
./bootgly test benchmark HTTP_Server_CLI
./bootgly test benchmark Progress_Bar
./bootgly test benchmark Template_Engine
```

### 3. Get help

```bash
# List available cases
./bootgly test benchmark --help

# Show case-specific options
./bootgly test benchmark HTTP_Server_CLI --help
```

---

## 🏗️ Architecture

The benchmark framework lives in `bootgly/Bootgly/ACI/Tests/Benchmark/` and provides:

- **Configs** — parsed CLI options (`--competitors`, `--runner`, `--scenarios`, `--vary`)
- **Competitor** — a named entry (with version and script path) to benchmark against
- **Runner** — abstract executor that drives each benchmark. Three built-in runners:
  | Runner | CLI Name | Use Case |
  |--------|----------|----------|
  | Code | `code` | Local code execution (time + memory) |
  | TCP_Client | `tcp_client` | HTTP load testing via Bootgly's TCP client |
  | WRK | `wrk` | HTTP load testing via external [wrk](https://github.com/wg/wrk) tool |
- **Scenario** — a request distribution script (Lua for WRK, PHP for TCP_Client)

Each benchmark case has a `@.php` entry-point that selects a runner, configures it, and registers competitors.
