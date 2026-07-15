<?php

use Bootgly\Benchmarks\Runners\WorkerWarmup;
use Bootgly\Benchmarks\Runners\WorkerWarmupFailure;

require_once dirname(__DIR__) . '/WorkerWarmup.php';


$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new RuntimeException($message);
   }
};

/**
 * Start a deterministic synthetic HTTP server.
 *
 * @param Closure(int,string,array<string,string>):array{
 *    status?:int,
 *    body?:string,
 *    identity?:?string,
 *    framing?:string,
 *    hold?:bool,
 *    linger?:int
 * } $Handler
 *
 * @return array{port:int,pid:int}
 */
$Spawn = static function (int $requests, Closure $Handler): array {
   $Server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
   if (!is_resource($Server)) {
      throw new RuntimeException("Could not start synthetic warmup server: {$errorMessage}");
   }

   $address = stream_socket_get_name($Server, false);
   $separator = is_string($address) ? strrpos($address, ':') : false;
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
   if ($port < 1) {
      fclose($Server);
      throw new RuntimeException('Could not resolve synthetic warmup port.');
   }

   $PID = pcntl_fork();
   if ($PID < 0) {
      fclose($Server);
      throw new RuntimeException('Could not fork synthetic warmup server.');
   }

   if ($PID === 0) {
      $Connections = [];
      $linger = 0;
      for ($index = 1; $index <= $requests; $index++) {
         $Connection = @stream_socket_accept($Server, 5);
         if (!is_resource($Connection)) {
            fclose($Server);
            exit(70);
         }
         stream_set_timeout($Connection, 2);

         $HTTP = '';
         while (!str_contains($HTTP, "\r\n\r\n")) {
            $chunk = fread($Connection, 4096);
            if ($chunk === false || $chunk === '') {
               fclose($Connection);
               fclose($Server);
               exit(71);
            }
            $HTTP .= $chunk;
            if (strlen($HTTP) > 65_536) {
               fclose($Connection);
               fclose($Server);
               exit(72);
            }
         }

         preg_match('/\A[A-Z]+\s+([^ ]+)\s+HTTP\/1\.1\r\n/D', $HTTP, $pathMatch);
         $path = $pathMatch[1] ?? '';
         $headers = [];
         foreach (explode("\r\n", strstr($HTTP, "\r\n") ?: '') as $line) {
            $headerSeparator = strpos($line, ':');
            if ($headerSeparator === false) {
               continue;
            }
            $name = strtolower(trim(substr($line, 0, $headerSeparator)));
            $headers[$name] = trim(substr($line, $headerSeparator + 1));
         }

         $response = $Handler($index, $path, $headers);
         $status = (int) ($response['status'] ?? 200);
         $body = (string) ($response['body'] ?? 'Hello, World!');
         $reason = $status === 200 ? 'OK' : ($status === 404 ? 'Not Found' : 'Error');
         $framing = (string) ($response['framing'] ?? 'length');
         $hold = ($response['hold'] ?? false) === true;
         $linger = max($linger, (int) ($response['linger'] ?? 0));
         $worker = '';
         if (is_string($response['identity'] ?? null)) {
            $marker = $headers['x-bootgly-benchmark-warmup'] ?? '';
            $authorization = $headers['authorization'] ?? '';
            $connection = strtolower($headers['connection'] ?? '');
            if (
               $marker !== ''
               && hash_equals("Bootgly-Warmup {$marker}", $authorization)
               && $connection === 'close'
            ) {
               $worker = "X-Bootgly-Benchmark-Worker: {$marker}:{$response['identity']}\r\n";
            }
         }

         $framingHeader = match ($framing) {
            'chunked' => "Transfer-Encoding: chunked\r\n",
            'none' => '',
            default => "Content-Length: " . strlen($body) . "\r\n",
         };
         $wireBody = $framing === 'chunked'
            ? dechex(strlen($body)) . "\r\n{$body}\r\n0\r\n\r\n"
            : $body;
         $raw = "HTTP/1.1 {$status} {$reason}\r\n"
            . $framingHeader
            . "Content-Type: text/plain\r\n"
            . $worker
            . 'Connection: ' . ($hold ? 'keep-alive' : 'close') . "\r\n\r\n"
            . $wireBody;
         fwrite($Connection, $raw);
         if ($hold) {
            $Connections[] = $Connection;
         }
         else {
            fclose($Connection);
         }
      }

      if ($linger > 0) {
         usleep($linger);
      }
      foreach ($Connections as $Connection) {
         fclose($Connection);
      }

      fclose($Server);
      exit(0);
   }

   fclose($Server);

   return [
      'port' => $port,
      'pid' => $PID,
   ];
};

$Join = static function (int $PID) use ($Check): void {
   $waited = pcntl_waitpid($PID, $status);
   $Check($waited === $PID, 'Synthetic warmup server was not reaped.');
   $Check(pcntl_wifexited($status), 'Synthetic warmup server did not exit normally.');
   $Check(pcntl_wexitstatus($status) === 0, 'Synthetic warmup server reported a protocol-test failure.');
};

$Reject = static function (Closure $Callback, string $message): WorkerWarmupFailure {
   try {
      $Callback();
   }
   catch (WorkerWarmupFailure $Exception) {
      return $Exception;
   }

   throw new RuntimeException($message);
};

$load = [
   'method' => 'GET',
   'paths' => ['/plaintext'],
   'expect' => [
      'status' => 200,
      'contains' => ['Hello, World!'],
   ],
];
$token = 'worker-warmup-test-secret';

// @ The selected route, both cache-safe request markers, exactly two worker
//   identities, and the same identity set must be proved in both rounds.
$server = $Spawn(
   6,
   static function (int $index, string $path, array $headers): array {
      if ($path === '/') {
         return ['status' => 404, 'body' => 'root route is intentionally unavailable', 'identity' => 'root'];
      }

      $marker = $headers['x-bootgly-benchmark-warmup'] ?? '';
      if ($index > 4 && ($headers['x-bootgly-benchmark-seal'] ?? '') !== $marker) {
         return ['status' => 200, 'body' => 'Hello, World!', 'identity' => null];
      }

      return [
         'status' => $path === '/plaintext' ? 200 : 404,
         'body' => $path === '/plaintext' ? 'Hello, World!' : 'missing',
         'identity' => $index % 2 === 1 ? 'worker-a' : 'worker-b',
      ];
   },
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$coverage = $Warmup->probe($load, $token, workers: 2, attempts: 2, budget: 3.0, parallel: 2);
$Join($server['pid']);

$Check($coverage['complete'] === true, 'Stable worker coverage was not marked complete.');
$Check($coverage['stable'] === true, 'Stable worker identities were not recognized.');
$Check($coverage['sealed'] === true, 'Stable worker identities were not sealed.');
$Check(count($coverage['rounds']) === 2, 'Warmup did not retain two independent rounds.');
$Check(count($coverage['workers']) === 2, 'Warmup did not prove exactly two worker identities.');
$Check($coverage['path_requests'] === ['/plaintext' => 6], 'Warmup did not probe and seal the selected load path.');
$Check($coverage['path_responses'] === ['/plaintext' => 6], 'Selected-path response coverage is incomplete.');
$Check(($coverage['statuses'][200] ?? 0) === 6, 'Selected-path status evidence is incomplete.');
$Check(($coverage['seal']['complete'] ?? false) === true, 'Worker seal evidence is incomplete.');
$Check(($coverage['seal']['unmarked'] ?? -1) === 0, 'Successful seal unexpectedly retained unmarked responses.');
$Check($coverage['failures'] === [], 'Successful probes retained failures.');
$Check($coverage['elapsed'] > 0, 'Warmup did not record monotonic duration.');

// @ Explicit HTTP/1 framing must finish a probe without waiting for EOF. The
//   synthetic peer keeps all three proof connections open until after both
//   rounds and the seal have received complete responses.
foreach (
   [
      ['framing' => 'length', 'status' => 200, 'body' => 'Hello, World!'],
      ['framing' => 'chunked', 'status' => 200, 'body' => 'Hello, World!'],
      ['framing' => 'none', 'status' => 204, 'body' => ''],
   ] as $case
) {
   $framedLoad = [
      'method' => 'GET',
      'paths' => ['/framed'],
      'expect' => [
         'status' => $case['status'],
         'contains' => $case['body'] === '' ? [] : [$case['body']],
      ],
   ];
   $server = $Spawn(
      3,
      static fn (int $index, string $path, array $headers): array => [
         'status' => $case['status'],
         'body' => $case['body'],
         'identity' => 'worker-a',
         'framing' => $case['framing'],
         'hold' => true,
         'linger' => 600_000,
      ],
   );
   $Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 0.2);
   $started = hrtime(true);
   $framedCoverage = $Warmup->probe(
      $framedLoad,
      $token,
      workers: 1,
      attempts: 1,
      budget: 1.5,
      parallel: 1,
   );
   $elapsed = (hrtime(true) - $started) / 1_000_000_000;
   $Join($server['pid']);

   $Check($framedCoverage['complete'] === true, "{$case['framing']} framing did not complete coverage.");
   $Check($elapsed < 0.5, "{$case['framing']} framing waited for connection EOF.");
}

// @ During the one-way seal, connections may revisit an already disabled
//   worker. Those valid, unmarked responses are ignored until every identity
//   from the stable proof set has acknowledged exactly once.
$server = $Spawn(
   8,
   static function (int $index, string $path, array $headers): array {
      static $sealedWorkers = [];

      $identity = ['worker-a', 'worker-b', 'worker-a', 'worker-b',
         'worker-a', 'worker-a', 'worker-b', 'worker-a'][$index - 1];
      if (($headers['x-bootgly-benchmark-seal'] ?? '') !== '') {
         if (isset($sealedWorkers[$identity])) {
            return ['identity' => null];
         }
         $sealedWorkers[$identity] = true;
      }

      return ['identity' => $identity];
   },
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$sealedCoverage = $Warmup->probe($load, $token, workers: 2, attempts: 4, budget: 3.0, parallel: 2);
$Join($server['pid']);
$Check(($sealedCoverage['seal']['complete'] ?? false) === true, 'One-way worker sealing did not converge.');
$Check(($sealedCoverage['seal']['requests'] ?? 0) === 4, 'Seal did not retry duplicate worker assignments.');
$Check(($sealedCoverage['seal']['unmarked'] ?? 0) === 2, 'Seal did not account for already-disabled workers.');

// @ A different second-round worker set must fail even when both cardinalities
//   independently match the configured worker count.
$server = $Spawn(
   4,
   static fn (int $index, string $path, array $headers): array => [
      'identity' => ['worker-a', 'worker-b', 'worker-a', 'worker-c'][$index - 1],
   ],
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 2, attempts: 2, budget: 3.0, parallel: 2),
   'A divergent second-round worker set was accepted.',
);
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['worker_set_changed'] ?? 0) === 1,
   'Divergent-set failure evidence was not retained.',
);

// @ Missing worker evidence, wrong status, and wrong body each fail closed.
$server = $Spawn(1, static fn (int $index, string $path, array $headers): array => ['identity' => null]);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'A missing worker header was accepted.',
);
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['worker_header'] ?? 0) === 1,
   'Missing-header failure evidence was not retained.',
);

$server = $Spawn(1, static fn (int $index, string $path, array $headers): array => [
   'status' => 500,
   'body' => 'Hello, World!',
   'identity' => 'worker-a',
]);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'An unexpected warmup status was accepted.',
);
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['unexpected_status'] ?? 0) === 1,
   'Unexpected-status failure evidence was not retained.',
);

$server = $Spawn(1, static fn (int $index, string $path, array $headers): array => [
   'body' => 'wrong body',
   'identity' => 'worker-a',
]);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'A warmup body mismatch was accepted.',
);
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['body_mismatch'] ?? 0) === 1,
   'Body-mismatch failure evidence was not retained.',
);

// @ The monotonic probe budget bounds socket I/O as well as the outer loop.
//   A stalled response must not consume the larger per-socket timeout.
$server = $Spawn(1, static function (int $index, string $path, array $headers): array {
   usleep(1_000_000);

   return ['identity' => 'worker-a'];
});
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$started = hrtime(true);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 0.15, parallel: 1),
   'A stalled warmup response exceeded its budget without failing.',
);
$elapsed = (hrtime(true) - $started) / 1_000_000_000;
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['budget_exhausted'] ?? 0) === 1,
   'Probe-budget failure evidence was not retained: '
      . json_encode($Exception->evidence['failures'] ?? [], JSON_THROW_ON_ERROR),
);
$Check($elapsed < 0.6, 'Socket I/O exceeded the monotonic warmup budget.');

// @ Sustained traffic is admitted only with a strict JSON document, closed
//   ledgers, declared/probed statuses, and terminal-only request failures.
$data = [
   'rps' => 8.0,
   'elapsed' => 1.25,
   'latency' => '1.00ms',
   'transfer' => '1.00KB/s',
   'scheduled' => 11,
   'sent' => 11,
   'responses' => 10,
   'informational' => 0,
   'outstanding' => 0,
   'failed' => 1,
   'write_failed' => 0,
   'connection_failed' => 0,
   'partial_writes' => 0,
   'accounting' => true,
   'statuses' => ['200' => 10],
   'failures' => ['measurement_ended' => 1],
   'write_failures' => [],
];
$JSON = json_encode($data, JSON_THROW_ON_ERROR);
$traffic = $Warmup->validate($JSON, $coverage, 1);
$Check($traffic['accounting'] === true, 'Valid sustained-warmup accounting was rejected.');

$invalid = $data;
$invalid['elapsed'] = 0.70;
$Reject(
   static fn (): array => $Warmup->validate(
      json_encode($invalid, JSON_THROW_ON_ERROR),
      $coverage,
      1,
   ),
   'A materially short sustained-warmup duration was accepted.',
);

$invalid = $data;
$invalid['elapsed'] = 2.30;
$Reject(
   static fn (): array => $Warmup->validate(
      json_encode($invalid, JSON_THROW_ON_ERROR),
      $coverage,
      1,
   ),
   'A materially long sustained-warmup duration was accepted.',
);

$invalid = $data;
$invalid['accounting'] = false;
$Reject(
   static fn (): array => $Warmup->validate(json_encode($invalid, JSON_THROW_ON_ERROR), $coverage, 1),
   'Invalid sustained-warmup accounting was accepted.',
);

$invalid = $data;
$invalid['statuses'] = ['200' => 9, '500' => 1];
$Reject(
   static fn (): array => $Warmup->validate(json_encode($invalid, JSON_THROW_ON_ERROR), $coverage, 1),
   'An undeclared sustained-warmup status was accepted.',
);

$invalid = $data;
$invalid['failures'] = ['protocol_error' => 1];
$Reject(
   static fn (): array => $Warmup->validate(json_encode($invalid, JSON_THROW_ON_ERROR), $coverage, 1),
   'A non-terminal sustained-warmup failure was accepted.',
);

$Reject(
   static fn (): array => $Warmup->validate($JSON . $JSON, $coverage, 1),
   'Two concatenated sustained-warmup JSON documents were accepted.',
);

// @ The v1 artifact is composed from whitelisted fields. Neither the marker
//   token nor its Authorization value may survive in persistable evidence.
$evidence = $Warmup->compose($coverage, $traffic);
$EvidenceJSON = json_encode($evidence, JSON_THROW_ON_ERROR);
$Check(($evidence['schema'] ?? null) === 'bootgly.worker-aware-warmup', 'Evidence schema is missing.');
$Check(($evidence['version'] ?? null) === 1, 'Evidence version is missing.');
$Check(($evidence['validated'] ?? null) === true, 'Evidence validation state is missing.');
$Check(!str_contains($EvidenceJSON, $token), 'Warmup evidence leaked the marker token.');
$Check(!str_contains($EvidenceJSON, 'Bootgly-Warmup'), 'Warmup evidence leaked the Authorization value.');

echo "WorkerWarmup tests: OK\n";
