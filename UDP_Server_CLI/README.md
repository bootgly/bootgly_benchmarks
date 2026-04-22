# Bootgly Benchmark — UDP_Server_CLI

Raw UDP server benchmark measuring Bootgly's datagram echo throughput without any HTTP or TCP framing overhead.

## Protocol

Each scenario defines a single **datagram payload**. The server echoes every received datagram back to the sender verbatim. One datagram sent → one datagram received = one response counted.

- **Echo**: Client sends an N-byte datagram → server echoes it back.

The handler registered in `Benchmark.project.php` is a plain echo:
```php
static function (string $input): string { return $input; }
```

## Competitors

| Name     | Description                        |
|----------|------------------------------------|
| bootgly  | Bootgly UDP_Server_CLI (baseline)  |

## Scenarios

| #   | File                      | Label         | Group |
|-----|---------------------------|---------------|-------|
| 1   | `1.1.1-echo_small.php`    | Echo 32 bytes | Echo  |

## Running

```bash
# All scenarios, all competitors
./bootgly test benchmark UDP_Server_CLI

# Specific scenario
./bootgly test benchmark UDP_Server_CLI --scenarios=1

# Custom options
./bootgly test benchmark UDP_Server_CLI --connections=256 --duration=15 --server-workers=8
```

## Runner

Uses the `UDP_Raw` runner (`runners/UDP_Raw.php`), which spawns a worker subprocess per scenario. The worker uses `UDP_Client_CLI` to open many concurrent UDP sockets and measure datagram throughput.

Server readiness is probed by sending a `PING\n` datagram and waiting for an echo reply (replaces the TCP `fsockopen` probe used by `TCP_Raw`).

### CLI Options

| Option               | Default | Description                           |
|----------------------|---------|---------------------------------------|
| `--connections=N`    | 514     | Number of concurrent UDP sockets      |
| `--duration=N`       | 10      | Benchmark duration in seconds         |
| `--client-workers=N` | auto    | Number of client worker processes     |
| `--server-workers=N` | auto    | Number of server worker processes     |

## Port

Default: **8084** (distinct from HTTP_Server_CLI:8082 and TCP_Server_CLI:8083 to allow parallel runs).
