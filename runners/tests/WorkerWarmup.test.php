<?php

use Bootgly\Benchmarks\Runners\WorkerWarmup;
use Bootgly\Benchmarks\Runners\WorkerWarmupFailure;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once dirname(__DIR__) . '/WorkerWarmup.php';


return new Specification(
   description: 'It should prove, validate and seal the complete worker-aware warmup protocol',
   test: static function (): bool
   {
$Check = static function (bool $condition, string $message): void {
   if (!$condition) {
      throw new AssertionError($message);
   }
};
$PIDs = [];

/**
 * Start a deterministic synthetic HTTP server.
 *
 * @param Closure(int,string,array<string,string>):array{
 *    status?:int,
 *    body?:string,
 *    identity?:?string,
 *    acknowledgement?:string,
 *    acknowledgements?:array<int,string>,
 *    framing?:string,
 *    hold?:bool,
 *    linger?:int
 * } $Handler
 *
 * @return array{port:int,pid:int}
 */
$Spawn = static function (int $requests, Closure $Handler) use (&$PIDs): array {
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
      $nonces = [];
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
         $nonce = $headers['x-bootgly-benchmark-nonce'] ?? '';
         if (
            preg_match('/\A[0-9a-f]{64}\z/D', $nonce) !== 1
            || isset($nonces[$nonce])
         ) {
            fclose($Connection);
            fclose($Server);
            exit(73);
         }
         $nonces[$nonce] = true;

         $response = $Handler($index, $path, $headers);
         $status = (int) ($response['status'] ?? 200);
         $body = (string) ($response['body'] ?? 'Hello, World!');
         $reason = $status === 200 ? 'OK' : ($status === 404 ? 'Not Found' : 'Error');
         $framing = (string) ($response['framing'] ?? 'length');
         $hold = ($response['hold'] ?? false) === true;
         $linger = max($linger, (int) ($response['linger'] ?? 0));
         $worker = '';
         $acknowledgements = [];
         if (is_array($response['acknowledgements'] ?? null)) {
            $acknowledgements = $response['acknowledgements'];
         }
         elseif (is_string($response['acknowledgement'] ?? null)) {
            $acknowledgements = [$response['acknowledgement']];
         }
         elseif (is_string($response['identity'] ?? null)) {
            $marker = $headers['x-bootgly-benchmark-warmup'] ?? '';
            $authorization = $headers['authorization'] ?? '';
            $connection = strtolower($headers['connection'] ?? '');
            if (
               $marker !== ''
               && hash_equals("Bootgly-Warmup {$marker}", $authorization)
               && $connection === 'close'
            ) {
               $acknowledgements[] = "{$marker}:{$nonce}:{$response['identity']}";
            }
         }
         foreach ($acknowledgements as $acknowledgement) {
            if (is_string($acknowledgement)) {
               $worker .= "X-Bootgly-Benchmark-Worker: {$acknowledgement}\r\n";
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
   $PIDs[$PID] = true;

   return [
      'port' => $port,
      'pid' => $PID,
   ];
};

$Join = static function (int $PID) use ($Check, &$PIDs): void {
   $waited = pcntl_waitpid($PID, $status);
   if ($waited === $PID) {
      unset($PIDs[$PID]);
   }
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

   throw new AssertionError($message);
};

$RejectArgument = static function (Closure $Callback, string $message): InvalidArgumentException {
   try {
      $Callback();
   }
   catch (InvalidArgumentException $Exception) {
      return $Exception;
   }

   throw new AssertionError($message);
};

try {
$issuedTokenA = WorkerWarmup::issue();
$issuedTokenB = WorkerWarmup::issue();
$Check(
   preg_match('/\A[0-9a-f]{64}\z/D', $issuedTokenA) === 1
      && preg_match('/\A[0-9a-f]{64}\z/D', $issuedTokenB) === 1
      && $issuedTokenA !== $issuedTokenB,
   'WorkerWarmup::issue() did not produce two distinct lowercase hexadecimal tokens.',
);

foreach (
   [
      ['', 8080, 1.0],
      ["unsafe\rhost", 8080, 1.0],
      ["unsafe\nhost", 8080, 1.0],
      ['127.0.0.1', 0, 1.0],
      ['127.0.0.1', 65_536, 1.0],
      ['127.0.0.1', 8080, 0.0],
      ['127.0.0.1', 8080, INF],
      ['127.0.0.1', 8080, NAN],
   ] as [$host, $port, $timeout]
) {
   $RejectArgument(
      static fn (): WorkerWarmup => new WorkerWarmup($host, $port, $timeout),
      'WorkerWarmup accepted an invalid constructor boundary.',
   );
}

$load = [
   'method' => 'GET',
   'paths' => ['/plaintext'],
   'expect' => [
      'status' => 200,
      'contains' => ['Hello, World!'],
   ],
];
$token = 'worker-warmup-test-secret';

// @ The public plan is the fail-closed contract shared by pre-sweep validation
//   and the runtime probe. It counts unique paths, separates matrix/seal caps,
//   and requires an explicit time budget beyond its certified automatic range.
$deduplicatedPlan = WorkerWarmup::plan([
   'paths' => ['/alpha', '/alpha', '/beta'],
], workers: 2);
$Check(
   ($deduplicatedPlan['unique_paths'] ?? null) === 2
      && ($deduplicatedPlan['cells'] ?? null) === 4,
   'Worker proof plan did not deduplicate paths before sizing the matrix.',
);

$paths4097 = array_map(static fn (int $index): string => "/path-{$index}", range(1, 4_097));
$automaticPlan = WorkerWarmup::plan(['paths' => array_slice($paths4097, 0, 4_096)], workers: 1);
$trivialPlan = WorkerWarmup::plan(['paths' => ['/only']], workers: 1);
$Check(
   ($automaticPlan['cells'] ?? null) === 4_096
      && ($automaticPlan['budget_source'] ?? null) === 'auto'
      && ($automaticPlan['auto_limit_cells'] ?? null) === 4_096
      && ($automaticPlan['budget_seconds'] ?? null) === 37.5
      && ($trivialPlan['budget_seconds'] ?? null) === 10.0
      && $automaticPlan['budget_seconds'] > $trivialPlan['budget_seconds'],
   'Worker proof plan rejected or misreported the automatic matrix boundary.',
);
$RejectArgument(
   static fn (): array => WorkerWarmup::plan(['paths' => $paths4097], workers: 1),
   'Worker proof plan accepted 4,097 automatic cells.',
);

$paths1000 = array_map(static fn (int $index): string => "/large-{$index}", range(1, 1_000));
$explicitPlan = WorkerWarmup::plan(['paths' => $paths1000], workers: 24, budget: '300');
$Check(
   ($explicitPlan['scheduler'] ?? null) === WorkerWarmup::SCHEDULER
      && ($explicitPlan['cells'] ?? null) === 24_000
      && ($explicitPlan['matrix_attempts'] ?? null) === 384_000
      && ($explicitPlan['seal_attempts'] ?? null) === 384
      && ($explicitPlan['budget_seconds'] ?? null) === 300.0
      && ($explicitPlan['budget_source'] ?? null) === 'explicit',
   'Explicit 24x1,000 worker proof plan was not sized independently and exactly.',
);

foreach ([0, -1, 'invalid', INF, NAN, 3_600.1] as $invalidBudget) {
   $RejectArgument(
      static fn (): array => WorkerWarmup::plan($load, workers: 1, budget: $invalidBudget),
      'Worker proof plan accepted an invalid explicit budget.',
   );
}
$RejectArgument(
   static fn (): array => WorkerWarmup::plan(
      ['paths' => ['/alpha', '/beta']],
      workers: 2,
      budget: 3,
      attempts: 3,
   ),
   'Worker proof plan accepted fewer attempts than matrix cells.',
);
$minimumAttemptPlan = WorkerWarmup::plan(
   ['paths' => $paths1000],
   workers: 24,
   budget: 300,
   attempts: 24_000,
);
$Check(
   ($minimumAttemptPlan['matrix_attempts'] ?? null) === 24_000
      && ($minimumAttemptPlan['seal_attempts'] ?? null) === 384,
   'An explicit matrix attempt cap incorrectly weakened the independent seal cap.',
);

// @ Both concrete clients expose the same budget control through configuration,
//   metadata, help and the clear Client banner subgroup.
$TCPRunner = include dirname(__DIR__) . '/TCP_Client.php';
$TCPRunner->configure(['client-workers' => 1, 'worker-proof-budget' => '300']);
$TCPOptions = $TCPRunner->options();
$TCPBanner = $TCPRunner->banner(Configs::parse([]));
$Check(
   isset($TCPOptions['--worker-proof-budget=auto|SECONDS'])
      && ($TCPRunner->meta['worker-proof-budget'] ?? null) === '300'
      && ($TCPBanner['Client']['Warmup traffic'] ?? null) === '5 s · selected load'
      && ($TCPBanner['Client']['Proof budget'] ?? null) === '300 s · explicit',
   'TCP client did not expose the worker proof budget consistently.',
);

$HTTPRunner = include dirname(__DIR__) . '/HTTP_Client.php';
$HTTPRunner->configure(['client-workers' => 1, 'worker-proof-budget' => '300']);
$HTTPOptions = $HTTPRunner->options();
$HTTPBanner = $HTTPRunner->banner(Configs::parse([]));
$Check(
   isset($HTTPOptions['--worker-proof-budget=auto|SECONDS'])
      && ($HTTPRunner->meta['worker-proof-budget'] ?? null) === '300'
      && ($HTTPBanner['Client']['Warmup traffic'] ?? null) === '5 s · selected load'
      && ($HTTPBanner['Client']['Proof budget'] ?? null) === '300 s · explicit',
   'HTTP client did not expose the worker proof budget consistently.',
);

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
$Check($coverage['cells_expected'] === 2, 'Warmup did not declare the worker/path matrix size.');
$Check($coverage['cells_covered'] === 2, 'Warmup did not cover the complete worker/path matrix.');
$Check(
   ($coverage['nonce']['issued'] ?? 0) === 6
      && ($coverage['nonce']['bound'] ?? 0) === 6
      && ($coverage['nonce']['replayed'] ?? -1) === 0,
   'Warmup nonce accounting did not close.',
);
$Check($coverage['path_requests'] === ['/plaintext' => 6], 'Warmup did not probe and seal the selected load path.');
$Check($coverage['path_responses'] === ['/plaintext' => 6], 'Selected-path response coverage is incomplete.');
$Check(($coverage['statuses'][200] ?? 0) === 6, 'Selected-path status evidence is incomplete.');
$Check(($coverage['seal']['complete'] ?? false) === true, 'Worker seal evidence is incomplete.');
$Check(($coverage['seal']['unmarked'] ?? -1) === 0, 'Successful seal unexpectedly retained unmarked responses.');
$Check($coverage['failures'] === [], 'Successful probes retained failures.');
$Check($coverage['elapsed'] > 0, 'Warmup did not record monotonic duration.');

// @ Covering the worker union and path union is insufficient. This sequence
//   reaches both workers and both paths, but correlates worker-a with /alpha and
//   worker-b with /beta, leaving half of the Cartesian matrix unproved.
$matrixLoad = [
   'method' => 'GET',
   'paths' => ['/alpha', '/beta'],
   'expect' => [
      'status' => 200,
      'contains' => ['Hello, World!'],
   ],
];
$server = $Spawn(
   4,
   static fn (int $index, string $path, array $headers): array => [
      'identity' => $index % 2 === 1 ? 'worker-a' : 'worker-b',
   ],
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe(
      $matrixLoad,
      $token,
      workers: 2,
      attempts: 4,
      budget: 3.0,
      parallel: 4,
   ),
   'Independent worker/path unions were accepted as a complete matrix.',
);
$Join($server['pid']);
$round = $Exception->evidence['rounds'][0] ?? [];
$Check(count($round['workers'] ?? []) === 2, 'Matrix rejection did not retain the worker union.');
$Check(!in_array(0, $round['path_responses'] ?? [], true), 'Matrix rejection did not retain the path union.');
$Check(($round['cells_expected'] ?? 0) === 4, 'Matrix rejection did not retain its expected cell count.');
$Check(($round['cells_covered'] ?? 0) === 2, 'Matrix rejection did not identify the two missing cells.');

// @ Every worker/path pair is proved independently in both rounds. Once the
//   initial batch leaves only worker-b × /beta missing, the next dispatch in
//   each round must target /beta. The seal remains a worker-only barrier.
$identities = [
   'worker-a', 'worker-a', 'worker-b', 'worker-a', 'worker-b',
   'worker-a', 'worker-a', 'worker-b', 'worker-a', 'worker-b',
   'worker-a', 'worker-b', null, null,
];
$server = $Spawn(
   count($identities),
   static function (int $index, string $path, array $headers) use ($identities): array {
      if (($index === 5 || $index === 10) && $path !== '/beta') {
         return ['status' => 500, 'identity' => 'worker-b'];
      }

      return ['identity' => $identities[$index - 1]];
   },
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$matrixCoverage = $Warmup->probe(
   $matrixLoad,
   $token,
   workers: 2,
   attempts: 5,
   budget: 3.0,
   parallel: 4,
);
$Join($server['pid']);
$Check($matrixCoverage['cells_expected'] === 4, 'Positive matrix proof declared the wrong shape.');
$Check($matrixCoverage['cells_covered'] === 4, 'Positive matrix proof did not cover all cells.');
$Check(
   ($matrixCoverage['rounds'][0]['cells_covered'] ?? 0) === 4
      && ($matrixCoverage['rounds'][1]['cells_covered'] ?? 0) === 4,
   'Positive matrix proof did not complete both independent rounds.',
);

// @ One-way sealing cannot and need not cover every path after a worker's first
//   acknowledgement. With two paths and one worker, both matrix rounds require
//   two cells while the seal requires only that single worker.
$server = $Spawn(
   5,
   static fn (int $index, string $path, array $headers): array => ['identity' => 'worker-a'],
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$workerOnlySeal = $Warmup->probe(
   $matrixLoad,
   $token,
   workers: 1,
   attempts: 2,
   budget: 3.0,
   parallel: 1,
);
$Join($server['pid']);
$Check(($workerOnlySeal['seal']['complete'] ?? false) === true, 'Worker-only seal did not complete.');
$Check(
   ($workerOnlySeal['seal']['cells_expected'] ?? 0) === 1
      && ($workerOnlySeal['seal']['cells_covered'] ?? 0) === 1,
   'Seal incorrectly retained worker/path matrix semantics.',
);
$Check(
   count(array_filter($workerOnlySeal['seal']['path_responses'] ?? [])) === 1,
   'Seal unexpectedly required every selected path.',
);

// @ A response replayed from an earlier nonce in the same token lifecycle must
//   fail closed instead of being attributed to the worker handling this request.
$server = $Spawn(
   2,
   static function (int $index, string $path, array $headers): array {
      static $acknowledgement = null;

      $acknowledgement ??= ($headers['x-bootgly-benchmark-warmup'] ?? '')
         . ':' . ($headers['x-bootgly-benchmark-nonce'] ?? '') . ':worker-a';

      return ['acknowledgement' => $acknowledgement];
   },
);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'A worker acknowledgement replayed under a stale nonce was accepted.',
);
$Join($server['pid']);
$Check(
   ($Exception->evidence['failures']['worker_nonce'] ?? 0) === 1
      && ($Exception->evidence['nonce']['replayed'] ?? 0) === 1,
   'Nonce replay failure evidence was not retained.',
);

// @ Old unbound acknowledgements and duplicate worker headers are invalid.
$server = $Spawn(1, static fn (int $index, string $path, array $headers): array => [
   'acknowledgement' => ($headers['x-bootgly-benchmark-warmup'] ?? '') . ':worker-a',
]);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'An acknowledgement without the request nonce was accepted.',
);
$Join($server['pid']);
$Check(($Exception->evidence['failures']['worker_nonce'] ?? 0) === 1, 'Unbound nonce failure was not retained.');

$server = $Spawn(1, static function (int $index, string $path, array $headers): array {
   $acknowledgement = ($headers['x-bootgly-benchmark-warmup'] ?? '')
      . ':' . ($headers['x-bootgly-benchmark-nonce'] ?? '') . ':worker-a';

   return ['acknowledgements' => [$acknowledgement, $acknowledgement]];
});
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe($load, $token, workers: 1, attempts: 1, budget: 3.0, parallel: 1),
   'Duplicate nonce-bound worker headers were accepted.',
);
$Join($server['pid']);
$Check(($Exception->evidence['failures']['worker_header'] ?? 0) === 1, 'Duplicate-header failure was not retained.');

// @ The supported resource contract is explicit and deliberately narrow: with
//   one effective DB slot, every worker's nonce-bound DB-path success proves its
//   sole required slot. Larger pools require explicit slot identities and fail.
$databaseLoad = $load;
$databaseLoad['readiness'] = ['resources' => ['database']];
$server = $Spawn(6, static fn (int $index, string $path, array $headers): array => [
   'identity' => $index % 2 === 1 ? 'worker-a' : 'worker-b',
]);
$Warmup = new WorkerWarmup('127.0.0.1', $server['port'], 2.0);
$databaseCoverage = $Warmup->probe(
   $databaseLoad,
   $token,
   workers: 2,
   attempts: 2,
   budget: 3.0,
   parallel: 2,
   effectivePoolSlots: 1,
);
$Join($server['pid']);
$workerSlots = $databaseCoverage['resources']['database']['worker_slots'] ?? [];
$Check(
   ($databaseCoverage['resources']['database']['complete'] ?? false) === true
      && ($databaseCoverage['resources']['database']['slots_expected'] ?? 0) === 2
      && ($databaseCoverage['resources']['database']['slots_covered'] ?? 0) === 2
      && count($workerSlots) === 2
      && array_values(array_unique($workerSlots)) === [1],
   'Single-slot database readiness was not proved per worker.',
);

$Warmup = new WorkerWarmup('127.0.0.1', 1, 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe(
      $databaseLoad,
      $token,
      workers: 1,
      attempts: 1,
      budget: 3.0,
      parallel: 1,
   ),
   'Database readiness without an effective pool size was accepted.',
);
$Check(
   ($Exception->evidence['failures']['pool_slot_attestation_unsupported'] ?? 0) === 1
      && array_key_exists('slots_expected_per_worker', $Exception->evidence['resources']['database'] ?? [])
      && $Exception->evidence['resources']['database']['slots_expected_per_worker'] === null,
   'Missing pool-slot evidence did not fail closed.',
);

$Warmup = new WorkerWarmup('127.0.0.1', 1, 2.0);
$Exception = $Reject(
   static fn (): array => $Warmup->probe(
      $databaseLoad,
      $token,
      workers: 1,
      attempts: 1,
      budget: 3.0,
      parallel: 1,
      effectivePoolSlots: 2,
   ),
   'A database pool above the supported single-slot proof was accepted.',
);
$Check(
   ($Exception->evidence['failures']['pool_slot_attestation_unsupported'] ?? 0) === 1
      && ($Exception->evidence['resources']['database']['complete'] ?? true) === false,
   'Unsupported pool-slot evidence was not retained.',
);

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

// @ The v2 artifact is composed from whitelisted fields. Neither the marker,
//   per-dispatch nonce, nor its Authorization value may survive in evidence.
$evidence = $Warmup->compose($coverage, $traffic);
$EvidenceJSON = json_encode($evidence, JSON_THROW_ON_ERROR);
$Check(($evidence['schema'] ?? null) === 'bootgly.worker-aware-warmup', 'Evidence schema is missing.');
$Check(($evidence['version'] ?? null) === 2, 'Evidence version is missing.');
$Check(($evidence['validated'] ?? null) === true, 'Evidence validation state is missing.');
$Check(($evidence['plan'] ?? null) === $coverage['plan'], 'Persisted evidence lost the worker proof plan.');
$Check(
   array_keys($evidence['coverage']['nonce'] ?? []) === ['algorithm', 'issued', 'bound', 'replayed']
      && array_keys($evidence['coverage']['rounds'][0]['nonce'] ?? []) === ['algorithm', 'issued', 'bound', 'replayed']
      && array_keys($evidence['coverage']['seal']['nonce'] ?? []) === ['algorithm', 'issued', 'bound', 'replayed'],
   'Persisted nonce evidence contains fields beyond the aggregate ledger.',
);
$Check(!str_contains($EvidenceJSON, $token), 'Warmup evidence leaked the marker token.');
$Check(!str_contains($EvidenceJSON, 'Bootgly-Warmup'), 'Warmup evidence leaked the Authorization value.');

$taintedCoverage = $coverage;
$taintedCoverage['nonce']['raw'] = str_repeat('a', 64);
$taintedCoverage['rounds'][0]['nonce']['raw'] = str_repeat('b', 64);
$taintedCoverage['seal']['nonce']['raw'] = str_repeat('c', 64);
$taintedEvidence = $Warmup->compose($taintedCoverage, $traffic);
$Check(
   !str_contains(json_encode($taintedEvidence, JSON_THROW_ON_ERROR), '"raw"'),
   'Nonce subledgers were persisted without an explicit field whitelist.',
);

return true;
}
finally {
   foreach (array_keys($PIDs) as $PID) {
      @posix_kill($PID, SIGKILL);
      pcntl_waitpid($PID, $status);
   }
}
   },
);
