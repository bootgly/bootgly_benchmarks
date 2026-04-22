# Bootgly Benchmark — TCP_Server_CLI

Raw TCP server benchmark comparing PHP frameworks at the socket level, without HTTP routing or middleware overhead.

## Protocol

The benchmark uses a **generic message/delimiter** protocol defined per scenario:

- **Echo**: Client sends a newline-terminated message → server echoes it back verbatim.
- **HTTP Raw**: Client sends a raw `GET / HTTP/1.1` request → server responds with a fixed `HTTP/1.1 200 OK` (no routing, no middleware).

Servers implement a **dual-mode handler**: if data starts with `GET `, respond with HTTP; otherwise, echo the data back.

## Competitors

| Name     | Description                        |
|----------|------------------------------------|
| bootgly  | Bootgly TCP_Server_CLI (baseline)  |

## Scenarios

| #   | File                      | Label                  | Group    |
|-----|---------------------------|------------------------|----------|
| 1   | `1.1.1-echo_small.php`    | Echo 32 bytes          | Echo     |
| 2   | `1.2.1-http_raw.php`      | HTTP raw (Hello World) | HTTP Raw |

## Running

```bash
# All scenarios, all competitors
./bootgly test benchmark TCP_Server_CLI

# Specific competitor and scenario
./bootgly test benchmark TCP_Server_CLI --competitors=bootgly,hibla --scenarios=1

# With custom options
./bootgly test benchmark TCP_Server_CLI --connections=256 --duration=15 --server-workers=8
```

## Runner

Uses the `TCP_Raw` runner (`runners/TCP_Raw.php`), which spawns a standalone worker process per scenario. The worker uses `TCP_Client_CLI` to open many concurrent connections and measure throughput.

### CLI Options

| Option               | Default | Description                       |
|----------------------|---------|-----------------------------------|
| `--connections=N`    | 514     | Number of TCP connections         |
| `--duration=N`       | 10      | Benchmark duration in seconds     |
| `--client-workers=N` | auto    | Number of client workers          |
| `--server-workers=N` | auto    | Number of server workers          |

## Port

Default: **8083** (different from HTTP_Server_CLI's 8082 to allow parallel runs).
