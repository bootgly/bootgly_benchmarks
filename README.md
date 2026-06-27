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
[HTTP Server CLI][BENCHMARK_03] | WPI | TechEmpower benchmark: 6 canonical routes (`/plaintext`, `/json`, `/db`, `/query`, `/fortunes`, `/updates`) vs opponents
[Cache][BENCHMARK_04] | ABI | Per-driver × per-operation matrix (File / APCu / Shared / Redis): store, fetch, increment, tags, resolve, ...
[TCP Server CLI][BENCHMARK_05] | WPI | Raw socket-level throughput (accept, read, write, close) — no HTTP routing/middleware. Loads: echo + raw HTTP
[UDP Server CLI][BENCHMARK_06] | WPI | Raw datagram echo throughput — no TCP framing. One datagram in → one echoed out

<!-- Links -->
[BENCHMARK_01]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Progress_Bar/README.md
[BENCHMARK_02]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Template_Engine/README.md
[BENCHMARK_03]: https://github.com/bootgly/bootgly_benchmarks/tree/main/HTTP_Server_CLI/README.md
[BENCHMARK_04]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Cache/README.md
[BENCHMARK_05]: https://github.com/bootgly/bootgly_benchmarks/tree/main/TCP_Server_CLI/README.md
[BENCHMARK_06]: https://github.com/bootgly/bootgly_benchmarks/tree/main/UDP_Server_CLI/README.md

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
./bootgly test benchmark TCP_Server_CLI
./bootgly test benchmark UDP_Server_CLI
./bootgly test benchmark Progress_Bar
./bootgly test benchmark Template_Engine
./bootgly test benchmark Cache
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

- **Configs** — parsed CLI options (`--opponents`, `--runner`, `--loads`, `--vary`)
- **Opponents** — a named entry (with version and script path) to benchmark against
- **Runner** — abstract executor that drives each benchmark. Built-in runners:
  | Runner | CLI Name | Use Case |
  |--------|----------|----------|
  | Code | `code` | Local code execution (time + memory) |
  | TCP_Client | `tcp_client` | HTTP load testing via Bootgly's TCP client |
- **Load** — a request distribution script (PHP for TCP_Client)

Each benchmark case has a `@.php` entry-point that selects a runner, configures it, and registers opponents.
