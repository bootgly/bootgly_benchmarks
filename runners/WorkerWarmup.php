<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — worker-aware HTTP warmup evidence
 * --------------------------------------------------------------------------
 * Proves that the selected load reaches the expected server-worker set in two
 * independent connection-close rounds, then validates the sustained TCP
 * worker's closed accounting before a measured run may start.
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\Runners;


final class WorkerWarmupFailure extends \RuntimeException
{
   /**
    * @param array<string,mixed> $evidence Safe-to-persist partial evidence.
    */
   public function __construct (
      string $message,
      public readonly array $evidence = [],
   )
   {
      parent::__construct($message);
   }
}


final class WorkerWarmup
{
   public const REQUEST_HEADER = 'X-Bootgly-Benchmark-Warmup';
   public const SEAL_HEADER = 'X-Bootgly-Benchmark-Seal';
   public const RESPONSE_HEADER = 'X-Bootgly-Benchmark-Worker';

   private const MAX_RESPONSE_BYTES = 16_777_216;
   /** Scheduler/clock boundary allowed below the requested traffic window. */
   private const EARLY_ELAPSED_TOLERANCE = 0.25;
   /** One terminal-drain tick plus modest scheduler delay above the window. */
   private const LATE_ELAPSED_TOLERANCE = 1.25;


   public function __construct (
      private readonly string $host,
      private readonly int $port,
      private readonly float $timeout = 3.0,
   )
   {
      if (
         $this->host === ''
         || \str_contains($this->host, "\r")
         || \str_contains($this->host, "\n")
      ) {
         throw new \InvalidArgumentException('Warmup host must be a safe non-empty string.');
      }
      if ($this->port < 1 || $this->port > 65_535) {
         throw new \InvalidArgumentException('Warmup port must be between 1 and 65535.');
      }
      if (!\is_finite($this->timeout) || $this->timeout <= 0) {
         throw new \InvalidArgumentException('Warmup timeout must be finite and positive.');
      }
   }

   /** Create one high-entropy per-opponent marker token. */
   public static function issue (): string
   {
      return \bin2hex(\random_bytes(32));
   }

   /**
    * Probe the selected HTTP load in two independent connection-close rounds.
    *
    * Every request carries both the warmup marker and an Authorization marker.
    * Authorization is intentional: Bootgly's route-wire cache bypasses both
    * lookup and storage for authorized requests, so marker headers cannot be
    * cached or replayed into another worker's response.
    *
    * Worker identities are compared internally and retained only as SHA-256
    * fingerprints. The marker token and Authorization value are never returned.
    *
    * @param array<string,mixed> $load Included load configuration.
    *
    * @return array<string,mixed> Safe-to-persist coverage evidence.
    */
   public function probe (
      array $load,
      string $token,
      int $workers,
      int $attempts = 0,
      float $budget = 10.0,
      int $parallel = 0,
   ): array
   {
      if (
         $token === ''
         || \strlen($token) > 256
         || \preg_match('/\A[A-Za-z0-9._~-]+\z/D', $token) !== 1
      ) {
         throw new \InvalidArgumentException('Warmup token must use a safe header-value alphabet.');
      }
      if ($workers < 1) {
         throw new \InvalidArgumentException('Expected worker count must be positive.');
      }
      if (!\is_finite($budget) || $budget <= 0) {
         throw new \InvalidArgumentException('Warmup probe budget must be finite and positive.');
      }

      $method = $load['method'] ?? 'GET';
      $paths = $load['paths'] ?? null;
      $expect = $load['expect'] ?? [];
      $strict = \array_key_exists('expect', $load);

      if (
         !\is_string($method)
         || \preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $method) !== 1
      ) {
         throw new \InvalidArgumentException('Warmup load method is invalid.');
      }
      if (!\is_array($paths) || $paths === []) {
         throw new \InvalidArgumentException('Warmup load paths must be a non-empty array.');
      }
      if (!\is_array($expect)) {
         throw new \InvalidArgumentException('Warmup load expectation must be an array.');
      }

      $selected = [];
      foreach ($paths as $path) {
         if (
            !\is_string($path)
            || $path === ''
            || $path[0] !== '/'
            || \str_contains($path, "\r")
            || \str_contains($path, "\n")
            || \str_contains($path, $token)
         ) {
            throw new \InvalidArgumentException('Warmup load contains an unsafe path.');
         }
         $selected[$path] = true;
      }
      $paths = \array_keys($selected);

      $declared = $expect['status'] ?? null;
      if ($declared !== null && (!\is_int($declared) || $declared < 100 || $declared > 599)) {
         throw new \InvalidArgumentException('Warmup expected status must be an HTTP status integer.');
      }

      $contains = $expect['contains'] ?? [];
      if (\is_string($contains)) {
         $contains = [$contains];
      }
      if (!\is_array($contains)) {
         throw new \InvalidArgumentException('Warmup body expectations must be strings.');
      }
      foreach ($contains as $needle) {
         if (!\is_string($needle) || $needle === '') {
            throw new \InvalidArgumentException('Warmup body expectations must be non-empty strings.');
         }
      }

      $minimum = \max($workers, \count($paths));
      $attempts = $attempts > 0 ? $attempts : \max(64, $workers * 16, \count($paths) * 2);
      if ($attempts < $minimum) {
         throw new \InvalidArgumentException('Warmup attempts cannot cover all workers and paths.');
      }
      $parallel = $parallel > 0
         ? $parallel
         : \min(128, \max($workers * 2, \count($paths)));
      if ($parallel < 1) {
         throw new \InvalidArgumentException('Warmup parallelism must be positive.');
      }

      $started = \hrtime(true);
      $coverage = [
         'schema' => 'bootgly.worker-coverage',
         'version' => 1,
         'method' => $method,
         'paths' => $paths,
         'declared_status' => $declared,
         'body_expectations' => \array_map(
            static fn (string $needle): string => 'sha256:' . \hash('sha256', $needle),
            $contains,
         ),
         'workers_expected' => $workers,
         'connection' => 'close',
         'cache_bypass' => 'authorization-marker',
         'rounds' => [],
         'requests' => 0,
         'responses' => 0,
         'failures' => [],
         'statuses' => [],
         'path_requests' => \array_fill_keys($paths, 0),
         'path_responses' => \array_fill_keys($paths, 0),
         'workers' => [],
         'stable' => false,
         'sealed' => false,
         'seal' => null,
         'complete' => false,
         'elapsed' => 0.0,
      ];

      for ($round = 1; $round <= 2; $round++) {
         $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
         $remaining = $budget - $elapsed;
         if ($remaining <= 0) {
            $coverage['failures']['budget_exhausted'] = 1;
            $coverage['elapsed'] = $elapsed;

            throw new WorkerWarmupFailure('Worker warmup exhausted its monotonic probe budget.', $coverage);
         }

         $proof = $this->survey(
            method: $method,
            paths: $paths,
            contains: $contains,
            declared: $declared,
            strict: $strict,
            token: $token,
            workers: $workers,
            attempts: $attempts,
            budget: $remaining,
            parallel: $parallel,
            index: $round,
         );
         $coverage['rounds'][] = $proof;
         $this->merge($coverage, $proof);

         if (($proof['complete'] ?? false) !== true) {
            $coverage['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;

            throw new WorkerWarmupFailure('Worker warmup could not prove complete worker and path coverage.', $coverage);
         }
      }

      $first = $coverage['rounds'][0]['workers'];
      $second = $coverage['rounds'][1]['workers'];
      $coverage['stable'] = $first === $second;
      if (!$coverage['stable']) {
         $coverage['failures']['worker_set_changed'] = 1;
         $coverage['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;

         throw new WorkerWarmupFailure('Worker identity set changed between warmup rounds.', $coverage);
      }

      $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
      $remaining = $budget - $elapsed;
      if ($remaining <= 0) {
         $coverage['failures']['budget_exhausted'] = 1;
         $coverage['elapsed'] = $elapsed;

         throw new WorkerWarmupFailure('Worker warmup exhausted its monotonic seal budget.', $coverage);
      }

      // @ Final barrier: every identity proved in both rounds must acknowledge
      //   a one-way seal. A sealed worker no longer emits evidence, so duplicate
      //   connections are valid but unmarked until all still-active workers are
      //   reached. No timed measurement starts unless the exact set converges.
      $seal = $this->survey(
         method: $method,
         paths: $paths,
         contains: $contains,
         declared: $declared,
         strict: $strict,
         token: $token,
         workers: $workers,
         attempts: $attempts,
         budget: $remaining,
         parallel: $parallel,
         index: 3,
         seal: true,
      );
      $coverage['seal'] = $seal;
      $this->merge($coverage, $seal);

      if (($seal['complete'] ?? false) !== true) {
         $coverage['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;

         throw new WorkerWarmupFailure('Worker warmup could not seal every proved worker.', $coverage);
      }
      if (($seal['workers'] ?? []) !== $first) {
         $coverage['failures']['seal_worker_set_changed'] = 1;
         $coverage['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;

         throw new WorkerWarmupFailure('Worker identity set changed during warmup sealing.', $coverage);
      }

      $coverage['workers'] = $first;
      $coverage['sealed'] = true;
      $coverage['complete'] = \count($first) === $workers
         && $coverage['failures'] === [];
      $coverage['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;

      if (!$coverage['complete']) {
         throw new WorkerWarmupFailure('Worker warmup evidence is incomplete.', $coverage);
      }

      return $coverage;
   }

   /**
    * Validate the sustained load worker's strict JSON/accounting document.
    * Elapsed time must stay within 250 ms below and 1.25 s above the requested
    * window. The upper bound admits one integer timer tick for terminal drain;
    * it does not admit an arbitrary positive or materially shorter run.
    *
    * @param array<string,mixed> $coverage Successful output from probe().
    * @param int $duration Requested sustained traffic window in seconds.
    *
    * @return array<string,mixed> Normalized, safe-to-persist traffic evidence.
    */
   public function validate (string $JSON, array $coverage, int $duration): array
   {
      if (
         ($coverage['schema'] ?? null) !== 'bootgly.worker-coverage'
         || ($coverage['version'] ?? null) !== 1
         || ($coverage['complete'] ?? false) !== true
         || ($coverage['stable'] ?? false) !== true
         || ($coverage['sealed'] ?? false) !== true
         || \count($coverage['rounds'] ?? []) !== 2
      ) {
         throw new WorkerWarmupFailure('Sustained warmup lacks complete worker-coverage evidence.', $coverage);
      }

      try {
         $data = \json_decode($JSON, true, flags: \JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $Exception) {
         throw new WorkerWarmupFailure('Sustained warmup did not emit one strict JSON document.', $coverage);
      }

      if (!\is_array($data)) {
         throw new WorkerWarmupFailure('Sustained warmup JSON must be an object.', $coverage);
      }
      if ($duration < 1) {
         throw new \InvalidArgumentException('Expected sustained warmup duration must be positive.');
      }

      $counters = [
         'scheduled', 'sent', 'responses', 'informational', 'outstanding',
         'failed', 'write_failed', 'connection_failed', 'partial_writes',
      ];
      foreach ($counters as $counter) {
         if (!\is_int($data[$counter] ?? null) || $data[$counter] < 0) {
            throw new WorkerWarmupFailure("Sustained warmup has an invalid {$counter} counter.", $coverage);
         }
      }

      $elapsed = $data['elapsed'] ?? null;
      $RPS = $data['rps'] ?? null;
      if (
         (!\is_int($elapsed) && !\is_float($elapsed))
         || !\is_finite((float) $elapsed)
         || (float) $elapsed <= 0
         || (!\is_int($RPS) && !\is_float($RPS))
         || !\is_finite((float) $RPS)
         || (float) $RPS <= 0
      ) {
         throw new WorkerWarmupFailure('Sustained warmup timing is invalid.', $coverage);
      }
      if (
         (float) $elapsed < $duration - self::EARLY_ELAPSED_TOLERANCE
         || (float) $elapsed > $duration + self::LATE_ELAPSED_TOLERANCE
      ) {
         throw new WorkerWarmupFailure(
            'Sustained warmup elapsed time does not match the requested duration.',
            $coverage,
         );
      }
      if (!\is_string($data['transfer'] ?? null) || ($data['transfer'] ?? '') === '') {
         throw new WorkerWarmupFailure('Sustained warmup transfer evidence is invalid.', $coverage);
      }
      if (
         !\array_key_exists('latency', $data)
         || ($data['latency'] !== null && !\is_string($data['latency']))
      ) {
         throw new WorkerWarmupFailure('Sustained warmup latency evidence is invalid.', $coverage);
      }

      $statuses = $this->normalize($data, 'statuses', $coverage, true);
      $failures = $this->normalize($data, 'failures', $coverage);
      $writeFailures = $this->normalize($data, 'write_failures', $coverage);

      $allowed = [];
      $declared = $coverage['declared_status'] ?? null;
      if (\is_int($declared)) {
         $allowed[$declared] = true;
      }
      foreach (($coverage['statuses'] ?? []) as $status => $count) {
         if (\is_int($status) && \is_int($count) && $count > 0) {
            $allowed[$status] = true;
         }
      }
      foreach ($statuses as $status => $count) {
         if (!isset($allowed[$status])) {
            throw new WorkerWarmupFailure('Sustained warmup observed an undeclared HTTP status.', $coverage);
         }
      }
      foreach ($failures as $reason => $count) {
         if ($reason !== 'measurement_ended') {
            throw new WorkerWarmupFailure('Sustained warmup observed a non-terminal request failure.', $coverage);
         }
      }

      $accounting = ($data['accounting'] ?? false) === true
         && $data['responses'] > 0
         && $data['connection_failed'] === 0
         && $data['write_failed'] === 0
         && $data['outstanding'] === 0
         && $writeFailures === []
         && $data['scheduled'] === $data['sent'] + $data['write_failed']
         && $data['sent'] === $data['responses'] + $data['failed']
         && $data['failed'] === \array_sum($failures)
         && $data['write_failed'] === \array_sum($writeFailures)
         && $data['responses'] === \array_sum($statuses);

      if (!$accounting) {
         throw new WorkerWarmupFailure('Sustained warmup accounting did not close.', $coverage);
      }

      return [
         'elapsed' => (float) $elapsed,
         'rps' => (float) $RPS,
         'latency' => isset($data['latency']) ? (string) $data['latency'] : null,
         'transfer' => (string) $data['transfer'],
         'scheduled' => $data['scheduled'],
         'sent' => $data['sent'],
         'responses' => $data['responses'],
         'informational' => $data['informational'],
         'outstanding' => $data['outstanding'],
         'failed' => $data['failed'],
         'write_failed' => $data['write_failed'],
         'connection_failed' => $data['connection_failed'],
         'partial_writes' => $data['partial_writes'],
         'accounting' => true,
         'statuses' => $statuses,
         'failures' => $failures,
         'write_failures' => $writeFailures,
      ];
   }

   /**
    * Compose the persistable v1 evidence document from validated inputs.
    *
    * @param array<string,mixed> $coverage Successful output from probe().
    * @param array<string,mixed> $traffic Successful output from validate().
    *
    * @return array<string,mixed>
    */
   public function compose (array $coverage, array $traffic): array
   {
      if (
         ($coverage['complete'] ?? false) !== true
         || ($coverage['stable'] ?? false) !== true
         || ($coverage['sealed'] ?? false) !== true
         || ($traffic['accounting'] ?? false) !== true
      ) {
         throw new WorkerWarmupFailure('Cannot compose unvalidated worker-warmup evidence.', $coverage);
      }

      $rounds = [];
      foreach ($coverage['rounds'] as $round) {
         $rounds[] = [
            'index' => $round['index'],
            'requests' => $round['requests'],
            'responses' => $round['responses'],
            'failures' => $round['failures'],
            'statuses' => $round['statuses'],
            'path_requests' => $round['path_requests'],
            'path_responses' => $round['path_responses'],
            'workers' => $round['workers'],
            'elapsed' => $round['elapsed'],
            'complete' => $round['complete'],
         ];
      }
      $seal = $coverage['seal'];

      return [
         'schema' => 'bootgly.worker-aware-warmup',
         'version' => 1,
         'validated' => true,
         'load' => [
            'method' => $coverage['method'],
            'paths' => $coverage['paths'],
            'declared_status' => $coverage['declared_status'],
            'body_expectations' => $coverage['body_expectations'],
         ],
         'coverage' => [
            'workers_expected' => $coverage['workers_expected'],
            'workers' => $coverage['workers'],
            'stable' => true,
            'sealed' => true,
            'connection' => 'close',
            'cache_bypass' => 'authorization-marker',
            'requests' => $coverage['requests'],
            'responses' => $coverage['responses'],
            'failures' => $coverage['failures'],
            'statuses' => $coverage['statuses'],
            'path_requests' => $coverage['path_requests'],
            'path_responses' => $coverage['path_responses'],
            'elapsed' => $coverage['elapsed'],
            'rounds' => $rounds,
            'seal' => [
               'requests' => $seal['requests'],
               'responses' => $seal['responses'],
               'unmarked' => $seal['unmarked'],
               'failures' => $seal['failures'],
               'statuses' => $seal['statuses'],
               'path_requests' => $seal['path_requests'],
               'path_responses' => $seal['path_responses'],
               'workers' => $seal['workers'],
               'elapsed' => $seal['elapsed'],
               'complete' => $seal['complete'],
            ],
         ],
         'traffic' => [
            'elapsed' => $traffic['elapsed'],
            'rps' => $traffic['rps'],
            'latency' => $traffic['latency'],
            'transfer' => $traffic['transfer'],
            'scheduled' => $traffic['scheduled'],
            'sent' => $traffic['sent'],
            'responses' => $traffic['responses'],
            'informational' => $traffic['informational'],
            'outstanding' => $traffic['outstanding'],
            'failed' => $traffic['failed'],
            'write_failed' => $traffic['write_failed'],
            'connection_failed' => $traffic['connection_failed'],
            'partial_writes' => $traffic['partial_writes'],
            'accounting' => true,
            'statuses' => $traffic['statuses'],
            'failures' => $traffic['failures'],
            'write_failures' => $traffic['write_failures'],
         ],
      ];
   }

   /**
    * Execute one independent coverage round.
    *
    * @param array<int,string> $paths
    * @param array<int,string> $contains
    *
    * @return array<string,mixed>
    */
   private function survey (
      string $method,
      array $paths,
      array $contains,
      ?int $declared,
      bool $strict,
      string $token,
      int $workers,
      int $attempts,
      float $budget,
      int $parallel,
      int $index,
      bool $seal = false,
   ): array
   {
      $started = \hrtime(true);
      $deadline = $started + (int) \ceil($budget * 1_000_000_000);
      $proof = [
         'index' => $index,
         'requests' => 0,
         'responses' => 0,
         'unmarked' => 0,
         'failures' => [],
         'statuses' => [],
         'path_requests' => \array_fill_keys($paths, 0),
         'path_responses' => \array_fill_keys($paths, 0),
         'workers' => [],
         'elapsed' => 0.0,
         'sealed' => $seal,
         'complete' => false,
      ];
      $identities = [];
      $cursor = 0;

      while ($proof['requests'] < $attempts) {
         if (\hrtime(true) >= $deadline) {
            $proof['failures']['budget_exhausted'] = 1;
            break;
         }

         $remaining = $attempts - $proof['requests'];
         $batch = \min($parallel, $remaining);
         $pending = [];

         for ($slot = 0; $slot < $batch; $slot++) {
            $path = $paths[$cursor % \count($paths)];
            $cursor++;
            $proof['requests']++;
            $proof['path_requests'][$path]++;

            $request = $this->dispatch($method, $path, $token, $deadline, $seal);
            if (($request['error'] ?? '') !== '') {
               $reason = (string) $request['error'];
               $proof['failures'][$reason] = ($proof['failures'][$reason] ?? 0) + 1;
               continue;
            }

            $pending[] = [
               'socket' => $request['socket'],
               'path' => $path,
            ];
         }

         foreach ($pending as $request) {
            $response = $this->receive($request['socket'], $deadline, $method);
            $path = $request['path'];
            if (($response['error'] ?? '') !== '') {
               $reason = (string) $response['error'];
               $proof['failures'][$reason] = ($proof['failures'][$reason] ?? 0) + 1;
               continue;
            }

            $proof['responses']++;
            $status = $response['status'];
            $proof['statuses'][$status] = ($proof['statuses'][$status] ?? 0) + 1;

            if ($declared !== null && $status !== $declared) {
               $proof['failures']['unexpected_status'] = ($proof['failures']['unexpected_status'] ?? 0) + 1;
               continue;
            }
            if ($strict && $declared === null && ($status < 200 || $status >= 300)) {
               $proof['failures']['unexpected_status'] = ($proof['failures']['unexpected_status'] ?? 0) + 1;
               continue;
            }

            $bodyValid = true;
            foreach ($contains as $needle) {
               if (!\str_contains($response['body'], $needle)) {
                  $bodyValid = false;
                  break;
               }
            }
            if (!$bodyValid) {
               $proof['failures']['body_mismatch'] = ($proof['failures']['body_mismatch'] ?? 0) + 1;
               continue;
            }

            $values = $response['headers'][\strtolower(self::RESPONSE_HEADER)] ?? [];
            if (\count($values) !== 1 || !\is_string($values[0])) {
               if ($seal) {
                  $proof['unmarked']++;
                  continue;
               }
               $proof['failures']['worker_header'] = ($proof['failures']['worker_header'] ?? 0) + 1;
               continue;
            }

            $prefix = $token . ':';
            $value = $values[0];
            if (
               \strlen($value) <= \strlen($prefix)
               || !\hash_equals($prefix, \substr($value, 0, \strlen($prefix)))
            ) {
               $proof['failures']['worker_header'] = ($proof['failures']['worker_header'] ?? 0) + 1;
               continue;
            }

            $identity = \substr($value, \strlen($prefix));
            if (
               \strlen($identity) > 256
               || \preg_match('/\A[A-Za-z0-9._:@~-]+\z/D', $identity) !== 1
            ) {
               $proof['failures']['worker_identity'] = ($proof['failures']['worker_identity'] ?? 0) + 1;
               continue;
            }

            $fingerprint = 'sha256:' . \hash('sha256', "worker\0{$identity}");
            $identities[$fingerprint] = true;
            $proof['path_responses'][$path]++;

            if (\count($identities) > $workers) {
               $proof['failures']['worker_overflow'] = 1;
            }
         }

         $pathsCovered = !\in_array(0, $proof['path_responses'], true);
         if (
            $proof['failures'] !== []
            || \count($identities) > $workers
         ) {
            break;
         }
         if (\count($identities) === $workers && $pathsCovered) {
            break;
         }
      }

      $proof['workers'] = \array_keys($identities);
      \sort($proof['workers'], \SORT_STRING);
      \ksort($proof['statuses'], \SORT_NUMERIC);
      \ksort($proof['failures'], \SORT_STRING);
      $proof['elapsed'] = (\hrtime(true) - $started) / 1_000_000_000;
      $proof['complete'] = $proof['failures'] === []
         && \count($proof['workers']) === $workers
         && !\in_array(0, $proof['path_responses'], true);

      if (!$proof['complete'] && $proof['failures'] === []) {
         $proof['failures']['coverage_incomplete'] = 1;
      }

      return $proof;
   }

   /** Open and write one marked HTTP connection-close probe. */
   private function dispatch (
      string $method,
      string $path,
      string $token,
      int $deadline,
      bool $seal = false,
   ): array
   {
      $remaining = ($deadline - \hrtime(true)) / 1_000_000_000;
      if ($remaining <= 0) {
         return ['error' => 'budget_exhausted'];
      }

      $target = \str_contains($this->host, ':')
         ? "tcp://[{$this->host}]:{$this->port}"
         : "tcp://{$this->host}:{$this->port}";
      $socket = @\stream_socket_client(
         $target,
         $errorNumber,
         $errorMessage,
         \min($this->timeout, $remaining),
      );
      if (!\is_resource($socket)) {
         return [
            'error' => \hrtime(true) >= $deadline
               ? 'budget_exhausted'
               : 'connection_failed',
         ];
      }

      $remaining = ($deadline - \hrtime(true)) / 1_000_000_000;
      if ($remaining <= 0) {
         \fclose($socket);

         return ['error' => 'budget_exhausted'];
      }
      $timeout = \min($this->timeout, $remaining);
      $seconds = (int) $timeout;
      $microseconds = (int) (($timeout - $seconds) * 1_000_000);
      if ($seconds === 0 && $microseconds === 0) {
         $microseconds = 1;
      }
      \stream_set_timeout($socket, $seconds, $microseconds);
      $HTTP = "{$method} {$path} HTTP/1.1\r\n"
         . "Host: {$this->host}:{$this->port}\r\n"
         . self::REQUEST_HEADER . ": {$token}\r\n"
         . ($seal ? self::SEAL_HEADER . ": {$token}\r\n" : '')
         . "Authorization: Bootgly-Warmup {$token}\r\n"
         . "Connection: close\r\n\r\n";
      $offset = 0;
      $length = \strlen($HTTP);

      while ($offset < $length) {
         $remaining = ($deadline - \hrtime(true)) / 1_000_000_000;
         if ($remaining <= 0) {
            \fclose($socket);

            return ['error' => 'budget_exhausted'];
         }
         $timeout = \min($this->timeout, $remaining);
         $seconds = (int) $timeout;
         $microseconds = (int) (($timeout - $seconds) * 1_000_000);
         if ($seconds === 0 && $microseconds === 0) {
            $microseconds = 1;
         }
         \stream_set_timeout($socket, $seconds, $microseconds);

         $written = @\fwrite($socket, \substr($HTTP, $offset));
         if ($written === false || $written === 0) {
            \fclose($socket);

            return [
               'error' => \hrtime(true) >= $deadline
                  ? 'budget_exhausted'
                  : 'write_failed',
            ];
         }
         $offset += $written;
      }

      return [
         'socket' => $socket,
         'error' => '',
      ];
   }

   /** Read and decode one HTTP/1-framed marked response. */
   private function receive ($socket, int $deadline, string $method): array
   {
      $raw = '';
      while (!\feof($socket)) {
         $remaining = ($deadline - \hrtime(true)) / 1_000_000_000;
         if ($remaining <= 0) {
            \fclose($socket);

            return ['error' => 'budget_exhausted'];
         }
         $bounded = $remaining <= $this->timeout;
         $timeout = \min($this->timeout, $remaining);
         $seconds = (int) $timeout;
         $microseconds = (int) (($timeout - $seconds) * 1_000_000);
         if ($seconds === 0 && $microseconds === 0) {
            $microseconds = 1;
         }
         \stream_set_timeout($socket, $seconds, $microseconds);

         $chunk = @\fread($socket, 8192);
         if ($chunk === false) {
            $meta = \stream_get_meta_data($socket);
            \fclose($socket);

            return [
               'error' => ($meta['timed_out'] ?? false) === true
                  && ($bounded || \hrtime(true) >= $deadline)
                     ? 'budget_exhausted'
                     : 'read_failed',
            ];
         }
         if ($chunk === '') {
            $meta = \stream_get_meta_data($socket);
            if (($meta['timed_out'] ?? false) === true) {
               \fclose($socket);

               return [
                  'error' => $bounded || \hrtime(true) >= $deadline
                     ? 'budget_exhausted'
                     : 'read_timeout',
               ];
            }
            continue;
         }

         $raw .= $chunk;
         if (\strlen($raw) > self::MAX_RESPONSE_BYTES) {
            \fclose($socket);

            return ['error' => 'response_too_large'];
         }

         $frame = $this->frame($raw, $method);
         if (($frame['error'] ?? '') !== '') {
            \fclose($socket);

            return ['error' => $frame['error']];
         }
         if (($frame['ready'] ?? false) === true) {
            \fclose($socket);

            return $this->decode(
               \substr($raw, 0, $frame['length']),
               $method,
            );
         }
      }
      \fclose($socket);

      // @ A response without explicit framing remains close-delimited. EOF is
      //   therefore still authoritative for legacy HTTP/1.0-style responses.
      return $this->decode($raw, $method);
   }

   /** Locate one complete HTTP/1 response without waiting for socket EOF. */
   private function frame (string $raw, string $method): array
   {
      $offset = 0;
      $head = '';
      $status = 0;

      do {
         $end = \strpos($raw, "\r\n\r\n", $offset);
         if ($end === false) {
            return ['ready' => false, 'length' => 0, 'error' => ''];
         }
         $head = \substr($raw, $offset, $end - $offset);
         if (\preg_match('/\AHTTP\/\d(?:\.\d)?\s+(\d{3})(?:\s|\z)/D', $head, $matches) !== 1) {
            return ['ready' => true, 'length' => $end + 4, 'error' => ''];
         }
         $status = (int) $matches[1];
         $offset = $end + 4;
      } while ($status >= 100 && $status < 200 && $status !== 101);

      if (
         \strtoupper($method) === 'HEAD'
         || $status === 101
         || $status === 204
         || $status === 304
      ) {
         return ['ready' => true, 'length' => $offset, 'error' => ''];
      }

      $lines = \explode("\r\n", $head);
      \array_shift($lines);
      $headers = [];
      foreach ($lines as $line) {
         $separator = \strpos($line, ':');
         if ($separator === false) {
            return ['ready' => true, 'length' => $offset, 'error' => ''];
         }
         $name = \strtolower(\trim(\substr($line, 0, $separator)));
         $value = \trim(\substr($line, $separator + 1));
         if ($name === '' || \preg_match('/\A[!#$%&\'*+.^_`|~0-9a-z-]+\z/D', $name) !== 1) {
            return ['ready' => true, 'length' => $offset, 'error' => ''];
         }
         $headers[$name][] = $value;
      }

      $transfers = $headers['transfer-encoding'] ?? [];
      if ($transfers !== []) {
         if (\count($transfers) !== 1 || \strtolower($transfers[0]) !== 'chunked') {
            return ['ready' => true, 'length' => $offset, 'error' => ''];
         }

         $cursor = $offset;
         while (true) {
            $end = \strpos($raw, "\r\n", $cursor);
            if ($end === false) {
               return ['ready' => false, 'length' => 0, 'error' => ''];
            }
            $line = \substr($raw, $cursor, $end - $cursor);
            $extension = \strpos($line, ';');
            $hex = $extension === false ? $line : \substr($line, 0, $extension);
            if ($hex === '' || \preg_match('/\A[0-9A-Fa-f]+\z/D', $hex) !== 1) {
               return ['ready' => true, 'length' => $end + 2, 'error' => ''];
            }
            $size = \hexdec($hex);
            if (!\is_int($size) || $size < 0) {
               return ['ready' => true, 'length' => $end + 2, 'error' => ''];
            }
            $cursor = $end + 2;

            if ($size === 0) {
               if (\substr($raw, $cursor, 2) === "\r\n") {
                  return ['ready' => true, 'length' => $cursor + 2, 'error' => ''];
               }
               $trailers = \strpos($raw, "\r\n\r\n", $cursor);
               if ($trailers === false) {
                  return ['ready' => false, 'length' => 0, 'error' => ''];
               }

               return ['ready' => true, 'length' => $trailers + 4, 'error' => ''];
            }

            if (\strlen($raw) < $cursor + $size + 2) {
               return ['ready' => false, 'length' => 0, 'error' => ''];
            }
            $cursor += $size;
            if (\substr($raw, $cursor, 2) !== "\r\n") {
               return ['ready' => true, 'length' => $cursor + 2, 'error' => ''];
            }
            $cursor += 2;
         }
      }

      $lengths = $headers['content-length'] ?? [];
      if ($lengths !== []) {
         if (
            \count($lengths) !== 1
            || \preg_match('/\A\d+\z/D', $lengths[0]) !== 1
         ) {
            return ['ready' => true, 'length' => $offset, 'error' => ''];
         }
         $length = (int) $lengths[0];
         if ($length > self::MAX_RESPONSE_BYTES - $offset) {
            return ['ready' => false, 'length' => 0, 'error' => 'response_too_large'];
         }
         if (\strlen($raw) < $offset + $length) {
            return ['ready' => false, 'length' => 0, 'error' => ''];
         }

         return ['ready' => true, 'length' => $offset + $length, 'error' => ''];
      }

      return ['ready' => false, 'length' => 0, 'error' => ''];
   }

   /** Decode the final HTTP/1 response and its transfer framing. */
   private function decode (string $raw, string $method): array
   {
      $offset = 0;
      $head = '';
      $status = 0;

      do {
         $end = \strpos($raw, "\r\n\r\n", $offset);
         if ($end === false) {
            return ['error' => 'invalid_response'];
         }
         $head = \substr($raw, $offset, $end - $offset);
         if (\preg_match('/\AHTTP\/\d(?:\.\d)?\s+(\d{3})(?:\s|\z)/D', $head, $matches) !== 1) {
            return ['error' => 'invalid_response'];
         }
         $status = (int) $matches[1];
         $offset = $end + 4;
      } while ($status >= 100 && $status < 200 && $status !== 101);

      if ($status === 101) {
         return ['error' => 'protocol_upgrade'];
      }

      $lines = \explode("\r\n", $head);
      \array_shift($lines);
      $headers = [];
      foreach ($lines as $line) {
         $separator = \strpos($line, ':');
         if ($separator === false) {
            return ['error' => 'invalid_response_header'];
         }
         $name = \strtolower(\trim(\substr($line, 0, $separator)));
         $value = \trim(\substr($line, $separator + 1));
         if ($name === '' || \preg_match('/\A[!#$%&\'*+.^_`|~0-9a-z-]+\z/D', $name) !== 1) {
            return ['error' => 'invalid_response_header'];
         }
         $headers[$name][] = $value;
      }

      $body = \substr($raw, $offset);
      if (
         \strtoupper($method) === 'HEAD'
         || $status === 204
         || $status === 304
      ) {
         $body = '';

         return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'error' => '',
         ];
      }

      $transfers = $headers['transfer-encoding'] ?? [];
      if ($transfers !== []) {
         if (\count($transfers) !== 1 || \strtolower($transfers[0]) !== 'chunked') {
            return ['error' => 'unsupported_transfer_encoding'];
         }
         $decoded = $this->unchunk($body);
         if ($decoded === null) {
            return ['error' => 'invalid_chunked_body'];
         }
         $body = $decoded;
      }
      elseif (isset($headers['content-length'])) {
         if (
            \count($headers['content-length']) !== 1
            || \preg_match('/\A\d+\z/D', $headers['content-length'][0]) !== 1
         ) {
            return ['error' => 'invalid_content_length'];
         }
         $length = (int) $headers['content-length'][0];
         if (\strlen($body) < $length) {
            return ['error' => 'truncated_body'];
         }
         $body = \substr($body, 0, $length);
      }

      return [
         'status' => $status,
         'headers' => $headers,
         'body' => $body,
         'error' => '',
      ];
   }

   /** Decode one complete HTTP chunked body. */
   private function unchunk (string $body): ?string
   {
      $decoded = '';
      $offset = 0;
      $length = \strlen($body);

      while ($offset < $length) {
         $end = \strpos($body, "\r\n", $offset);
         if ($end === false) {
            return null;
         }
         $line = \substr($body, $offset, $end - $offset);
         $extension = \strpos($line, ';');
         $hex = $extension === false ? $line : \substr($line, 0, $extension);
         if ($hex === '' || \preg_match('/\A[0-9A-Fa-f]+\z/D', $hex) !== 1) {
            return null;
         }
         $size = \hexdec($hex);
         if (!\is_int($size) || $size < 0) {
            return null;
         }
         $offset = $end + 2;
         if ($size === 0) {
            if (\substr($body, $offset, 2) === "\r\n") {
               return $decoded;
            }
            $trailers = \strpos($body, "\r\n\r\n", $offset);

            return $trailers === false ? null : $decoded;
         }
         if ($offset + $size + 2 > $length) {
            return null;
         }
         $decoded .= \substr($body, $offset, $size);
         $offset += $size;
         if (\substr($body, $offset, 2) !== "\r\n") {
            return null;
         }
         $offset += 2;
      }

      return null;
   }

   /** Normalize one positive-count ledger map. */
   private function normalize (
      array $data,
      string $field,
      array $coverage,
      bool $statuses = false,
   ): array
   {
      if (!\is_array($data[$field] ?? null)) {
         throw new WorkerWarmupFailure("Sustained warmup {$field} ledger is invalid.", $coverage);
      }

      $normalized = [];
      foreach ($data[$field] as $key => $count) {
         if (!\is_int($count) || $count <= 0) {
            throw new WorkerWarmupFailure("Sustained warmup {$field} ledger count is invalid.", $coverage);
         }
         if ($statuses) {
            if (!\is_int($key) || $key < 100 || $key > 599) {
               throw new WorkerWarmupFailure('Sustained warmup status ledger is invalid.', $coverage);
            }
         }
         elseif (!\is_string($key) || $key === '') {
            throw new WorkerWarmupFailure("Sustained warmup {$field} ledger key is invalid.", $coverage);
         }
         $normalized[$key] = $count;
      }
      if ($statuses) {
         \ksort($normalized, \SORT_NUMERIC);
      }
      else {
         \ksort($normalized, \SORT_STRING);
      }

      return $normalized;
   }

   /** Merge one round's scalar ledgers into aggregate coverage evidence. */
   private function merge (array &$coverage, array $proof): void
   {
      $coverage['requests'] += $proof['requests'];
      $coverage['responses'] += $proof['responses'];
      foreach (['failures', 'statuses', 'path_requests', 'path_responses'] as $ledger) {
         foreach ($proof[$ledger] as $key => $count) {
            $coverage[$ledger][$key] = ($coverage[$ledger][$key] ?? 0) + $count;
         }
      }
      \ksort($coverage['failures'], \SORT_STRING);
      \ksort($coverage['statuses'], \SORT_NUMERIC);
   }
}
