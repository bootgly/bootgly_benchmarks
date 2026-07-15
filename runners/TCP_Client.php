<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — TCP_Client Runner
 * --------------------------------------------------------------------------
 * HTTP benchmark runner using Bootgly TCP_Client_CLI as load generator.
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Loads;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\Benchmarks\Runners\RunProcess;
use Bootgly\Benchmarks\Runners\ServerReadiness;
use Bootgly\Benchmarks\Runners\WorkerWarmup;
use Bootgly\Benchmarks\Runners\WorkerWarmupFailure;

require_once __DIR__ . '/RunArtifacts.php';
require_once __DIR__ . '/ServerReadiness.php';
require_once __DIR__ . '/WorkerWarmup.php';


return new class (
   port: 8080,
   connections: 514,
   duration: 10,
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'tcp_client';

   // * Config
   public int $port;
   public int $connections;
   public int $duration;
   public int $workers = 0;
   public int $pipeline = 1;
   public int $preflightTimeout = 3;
   // # preflight retry — a measured load leaves the server (esp. blocking
   //   nginx+PHP-FPM) draining its 514-connection backlog; the next load's
   //   preflight can hit that congestion and read-timeout. Retry with a
   //   recovery pause so a transient "could not read response" does not turn a
   //   working route into N/A. Adaptive: fast async servers pass on attempt 1
   //   (no delay); slow servers drain and pass on a later attempt.
   public int $preflightRetries = 5;
   public int $preflightRecovery = 2;
   // # warmup
   public int $warmupDuration = 5;
   // # server readiness
   public int $readyTimeout = 10;

   // * Metadata
   // # Opponent start output — surfaced when readiness fails.
   private ?RunArtifacts $ServerArtifacts = null;
   private ?RunProcess $ServerProcess = null;
   private string $serverLog = '';
   private string $serverError = '';


   public function __construct (
      int $port = 8080,
      int $connections = 514,
      int $duration = 10,
   )
   {
      $this->port = $port;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'HTTP server stopped after benchmark run.';
   }

   public function configure (array $options): void
   {
      if (isset($options['connections'])) {
         $this->connections = (int) $options['connections'];
      }
      if (isset($options['duration'])) {
         $this->duration = (int) $options['duration'];
      }
      if (isset($options['client-workers'])) {
         $this->workers = (int) $options['client-workers'];
      }
      if (isset($options['pipeline'])) {
         $this->pipeline = (int) $options['pipeline'];
      }
      if (isset($options['warmup'])) {
         $this->warmupDuration = (int) $options['warmup'];
      }
      if ($this->warmupDuration < 1) {
         throw new InvalidArgumentException('--warmup must be a positive number of seconds.');
      }

      // ! Materialise the auto client-worker count so $this->meta captures the
      //   resolved value (nproc / 2) instead of the `0` auto sentinel.
      $this->workers = $this->resolveClientWorkers();

      // @ Surface active runner config for the `.marks` Config header.
      //   TestCommand merges this into the metadata block written by Summary::save.
      $this->meta['connections']    = $this->connections;
      $this->meta['duration']       = $this->duration;
      $this->meta['client-workers'] = $this->workers;
      $this->meta['pipeline']       = $this->pipeline;
      $this->meta['warmup']         = $this->warmupDuration;

   }
   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of TCP connections (default: 514)',
         '--duration=N'       => 'Benchmark duration in seconds (default: 10)',
         '--pipeline=N'       => 'HTTP pipelining factor (default: 1)',
         '--warmup=N'         => 'Selected-load warmup duration in seconds (default: 5)',
      ];
   }

   /**
    * Load loads from a directory of .php files.
    *
    * @param string $loadsDir Absolute path.
    */
   public function load (string $loadsDir): void
   {
      $this->loads = Loads::loadPhp($loadsDir);
   }
   /**
    * Resolve the client worker count.
    * When workers = 0 (auto), defaults to nproc / 2.
    */
   private function resolveClientWorkers (): int
   {
      if ($this->workers > 0) {
         return $this->workers;
      }

      return max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2));
   }
   /**
    * @return array<string,array<string,string>>
    */
   public function banner (Configs $Configs): array
   {
      $sections = [
         'Client' => [
            'Engine'         => 'Bootgly TCP_Client_CLI',
            'Client workers' => (string) $this->resolveClientWorkers(),
            'Connections'    => (string) $this->connections,
            'Duration'       => "{$this->duration} s",
            'Pipeline'       => (string) $this->pipeline,
            'Warmup'         => "{$this->warmupDuration} s · selected load · worker proof",
         ],
         'Server' => [
            'Port' => (string) $this->port,
         ],
      ];

      if (isset($this->meta['db-pool-max'])) {
         $sections['Database'] = [
            'Pool max / worker' => (string) $this->meta['db-pool-max'],
            'Parity'            => isset($this->meta['db-pool-comparability'])
               ? 'Capability contract validated'
               : 'Not applicable · no DB load selected',
         ];
      }

      return $sections;
   }
   public function run (Configs $Configs): array
   {
      // ANSI codes
      $BLUE   = self::wrap(self::_BLUE_FOREGROUND);
      $GREEN  = self::wrap(self::_GREEN_FOREGROUND);
      $RED    = self::wrap(self::_RED_FOREGROUND);
      $BOLD   = self::wrap(self::_BOLD_STYLE);
      $DIM    = self::wrap(self::_DIM_STYLE);
      $RESET  = self::_RESET_FORMAT;

      $results = [];

      // @ Install SIGINT handler to stop server on CTRL+C
      $activeOpponent = null;
      $stopping = false;
      $interrupted = false;
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, function () use (&$activeOpponent, &$stopping, &$interrupted) {
         if ($stopping) {
            $interrupted = true;
            return;
         }
         if ($activeOpponent !== null) {
            $Opponent = $activeOpponent;
            $activeOpponent = null;
            $stopping = true;
            $this->stopServer($Opponent);
         }
         exit(130);
      });

      // @ Pre-filter loads for counting
      $filteredLoads = [];
      foreach ($this->loads as $index => $Load) {
         if ($Configs->loads !== null && !in_array($index + 1, $Configs->loads)) {
            continue;
         }
         $filteredLoads[$index] = $Load;
      }
      $totalLoads = count($filteredLoads);

      foreach ($this->opponents as $Opponent) {
         // ? Filter (slug-normalized, e.g. "Laravel Octane" matches "laravel-octane")
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         putenv('BENCHMARK_PROFILE_SCOPE=' . Configs::slug($Opponent->name));

         // ! Server worker count — set by Runner::apply (or auto: nproc / 2)
         $workers = $Opponent->workers
            ?? max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

         try {
         // @@ Run loads
         $loadResults = [];
         $loadNum = 0;
         $prevGroup = '';

         foreach ($this->loads as $index => $Load) {
            // ? Filter by load index (1-based)
            if ($Configs->loads !== null && !in_array($index + 1, $Configs->loads)) {
               continue;
            }

            // ? Skip if load restricts opponents
            if (
               $Load->opponents !== 'all'
               && !in_array($Opponent->name, explode(',', $Load->opponents))
            ) {
               continue;
            }

            // @ Group header
            if ($Load->group !== '' && $Load->group !== $prevGroup) {
               echo "    {$BOLD}{$Load->group}{$RESET}\n";
               $prevGroup = $Load->group;
            }

            $loadNum++;

            // @ Worker evidence is sealed permanently inside each server process.
            // ! Give every load a fresh server lifecycle so its own selected-load
            // ! warmup can be proved and then fully removed before measurement.
            $warmupToken = WorkerWarmup::issue();
            $this->killPort();
            $activeOpponent = $Opponent;
            $stopping = false;

            echo "  {$BOLD}{$BLUE}▸ Starting {$Opponent->name}...{$RESET}\n";
            $this->boot($Opponent, $workers, $warmupToken);

            // @ Wait for server readiness
            if ( !$this->waitForServer($Opponent) ) {
               echo "    {$RED}{$Opponent->name} failed to start!{$RESET}\n";
               // ? Surface the opponent's own start output (e.g. capability guard message).
               $files = [
                  $this->serverLog,
                  $this->serverError,
                  $this->ServerArtifacts?->resolve('process.capture/stdout.log'),
                  $this->ServerArtifacts?->resolve('process.capture/stderr.log'),
                  $this->ServerArtifacts?->resolve('daemon/stdout.log'),
                  $this->ServerArtifacts?->resolve('daemon/stderr.log'),
                  $this->ServerArtifacts?->resolve('daemon.capture/stdout.log'),
                  $this->ServerArtifacts?->resolve('daemon.capture/stderr.log'),
               ];
               $output = '';
               foreach ($files as $file) {
                  $output .= is_string($file) && is_file($file)
                     ? (string) file_get_contents($file) . "\n"
                     : '';
               }
               $output = trim($output);
               foreach ($output === '' ? [] : array_slice(explode("\n", $output), -6) as $line) {
                  echo "    {$DIM}{$line}{$RESET}\n";
               }
               echo "\n";
               $stopping = true;
               $this->stopServer($Opponent);
               $activeOpponent = null;
               $stopping = false;
               if ($interrupted) {
                  exit(130);
               }
               continue 2;
            }

            echo "    {$GREEN}{$Opponent->name} ready (port {$this->port}).{$RESET}\n";

            $Result = $this->command(
               $Load,
               $warmupToken,
               $workers,
               $Opponent->name,
               $this->connections,
               $this->workers,
            );

            // @ A sealed server cannot prove another load. Stop it after the
            // @ measured window; the next load receives a fresh process/token.
            $stopping = true;
            $this->stopServer($Opponent);
            $activeOpponent = null;
            $stopping = false;
            if ($interrupted) {
               exit(130);
            }

            // @ Real-time result
            $rps = $Result->rps !== null
               ? "{$BOLD}{$GREEN}" . number_format((int) $Result->rps) . " req/s{$RESET}"
               : "{$RED}N/A{$RESET}";
            $transfer = $Result->transfer !== null
               ? "  {$DIM}({$Result->transfer}){$RESET}"
               : '';
            $latency = $Result->latency !== null
               ? "  {$DIM}({$Result->latency}){$RESET}"
               : '';
            $accounting = match ($Result->accounting) {
               true => "  {$DIM}(responses={$Result->responses}, sent={$Result->sent}, failed={$Result->failed}, write-failed={$Result->writeFailed}, connection-failed={$Result->connectionFailed}){$RESET}",
               false => "  {$RED}(accounting invalid){$RESET}",
               null => '',
            };

            echo "    {$DIM}[{$loadNum}/{$totalLoads}]{$RESET} {$Load->label}...  {$rps}{$transfer}{$latency}{$accounting}\n";

            $loadResults[$Load->label] = $Result;
         }

         echo "\n";

         $results[$Opponent->name] = $loadResults;
         }
         catch (Throwable $Throwable) {
            if (!$stopping && $activeOpponent !== null) {
               $CleanupOpponent = $activeOpponent;
               $activeOpponent = null;
               $stopping = true;

               try {
                  $this->stopServer($CleanupOpponent);
               }
               catch (Throwable) {
                  // @ Preserve the original benchmark failure.
               }
            }

            $activeOpponent = null;
            putenv('BENCHMARK_PROFILE_SCOPE');
            throw $Throwable;
         }
      }

      putenv('BENCHMARK_PROFILE_SCOPE');

      return $results;
   }

   // # Server lifecycle
   private function killPort (): void
   {
      exec("lsof -ti :{$this->port} 2>/dev/null | xargs kill -9 2>/dev/null");
      usleep(500_000);
   }
   private function boot (Opponent $Opponent, int $workers, string $token): void
   {
      $script = $Opponent->script;
      putenv("BOOTGLY_WORKERS={$workers}");
      $this->ServerArtifacts?->clean();
      $this->ServerArtifacts = RunArtifacts::create('tcp-client-server');
      $this->serverLog = $this->ServerArtifacts->resolve('process/stdout.log');
      $this->serverError = $this->ServerArtifacts->resolve('process/stderr.log');
      $environment = [
         'BENCHMARK_SERVER_DIR' => $this->ServerArtifacts->directory,
         'BENCHMARK_WARMUP_TOKEN' => $token,
         'BOOTGLY_WORKERS' => (string) $workers,
      ];
      if (isset($this->meta['db-pool-max'])) {
         $environment['DB_POOL_MAX'] = (string) $this->meta['db-pool-max'];
      }
      $this->ServerProcess = $this->ServerArtifacts->start(
         [PHP_BINARY, $script, 'start'],
         $environment,
      );
   }
   private function stopServer (Opponent $Opponent): void
   {
      $script = $Opponent->script;
      $Artifacts = RunArtifacts::create('tcp-client-server-stop');
      $serverDirectory = $this->ServerArtifacts?->directory;
      $environment = is_string($serverDirectory) && $serverDirectory !== ''
         ? ['BENCHMARK_SERVER_DIR' => $serverDirectory]
         : [];

      try {
         $stop = $Artifacts->start(
            [PHP_BINARY, $script, 'stop'],
            $environment,
         )->wait(10.0, 2.0);
         if ($stop['exit'] !== 0) {
            throw new RuntimeException(
               "Benchmark opponent stop command failed with exit {$stop['exit']}: {$Opponent->name}"
            );
         }
      }
      finally {
         $Artifacts->clean();
         sleep(1);
         $this->killPort();

         try {
            $this->ServerProcess?->wait(10.0, 2.0);
         }
         finally {
            $this->ServerProcess = null;
            $this->ServerArtifacts?->clean();
            $this->ServerArtifacts = null;
         }
      }
   }
   private function waitForServer (Opponent $Opponent): bool
   {
      $deadline = time() + $this->readyTimeout;
      $swoole = basename($Opponent->script) === 'swoole.php';
      $PIDFile = $swoole
         ? $this->ServerArtifacts?->resolve('swoole.pid')
         : null;
      $bootable = strtolower((string) getenv('BENCHMARK_LOAD_SET')) === 'techempower'
         ? 'swoole-techempower-postgres.php'
         : 'swoole-base-routes.php';

      while (time() < $deadline) {
         if ($swoole) {
            if ($this->ServerProcess === null || !$this->ServerProcess->check()) {
               return false;
            }
            $identity = ServerReadiness::inspect($PIDFile, $bootable);
            if ($identity === null) {
               usleep(50_000);

               continue;
            }
         }
         else {
            $identity = null;
         }

         if (!ServerReadiness::probe($this->port)) {
            usleep(250_000);

            continue;
         }
         if ($swoole && (
            $this->ServerProcess === null
            || !$this->ServerProcess->check()
            || ServerReadiness::inspect($PIDFile, $bootable) !== $identity
         )) {
            return false;
         }

         return true;
      }

      return false;
   }

   /**
    * Compute round configurations based on vary settings.
    *
    * @param array<string,int> $vary
    *
    * @return array<array{workers: int, connections: int, client-workers: int}>
    */

   // # Benchmark execution
   /**
    * Run sustained selected-load traffic with the measured shape, then prove
    * stable worker coverage as the final barrier before measurement.
    *
    * @param array<string,mixed> $load
    */
   private function warmup (
      array $load,
      string $JSON,
      string $token,
      int $serverWorkers,
      int $connections,
      int $clientWorkers,
      int $pipeline,
      string $opponent,
      string $label,
   ): bool
   {
      $workerScript = __DIR__ . '/TCP_Client/worker.php';
      $Artifacts = RunArtifacts::create('tcp-client-warmup');
      $input = $Artifacts->write('input.json', $JSON);
      $Warmup = new WorkerWarmup('127.0.0.1', $this->port, (float) $this->preflightTimeout);
      $context = [
         'opponent' => $opponent,
         'label' => $label,
         'load_input_sha256' => 'sha256:' . hash('sha256', $JSON),
         'requested_warmup_duration' => $this->warmupDuration,
         'server_workers' => $serverWorkers,
         'client_workers' => $clientWorkers,
         'connections' => $connections,
         'pipeline' => $pipeline,
         'db_pool_max' => isset($this->meta['db-pool-max'])
            ? (int) $this->meta['db-pool-max']
            : null,
      ];
      $coverage = [];
      $execution = null;

      try {
         $command = [
            PHP_BINARY,
            $workerScript,
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$connections}",
            "--duration={$this->warmupDuration}",
            "--paths-file={$input}",
            "--workers={$clientWorkers}",
         ];
         if ($pipeline > 1) {
            $command[] = "--pipeline={$pipeline}";
         }

         $execution = $Artifacts->run($command, $this->warmupDuration + 30.0, 2.0);
         if ($execution['exit'] !== 0) {
            throw new WorkerWarmupFailure(
               "Sustained warmup worker exited with status {$execution['exit']}.",
               $coverage,
            );
         }

         $output = @file_get_contents($execution['stdout']);
         if ($output === false) {
            throw new WorkerWarmupFailure('Sustained warmup output could not be read.', $coverage);
         }

         $budget = min(30.0, max(10.0, 5.0 + ($serverWorkers * 0.5)));
         $coverage = $Warmup->probe(
            load: $load,
            token: $token,
            workers: $serverWorkers,
            budget: $budget,
         );
         $traffic = $Warmup->validate($output, $coverage, $this->warmupDuration);
         $evidence = $Warmup->compose($coverage, $traffic);
         $evidence['context'] = $context;
         $Artifacts->write(
            'evidence.json',
            json_encode(
               $evidence,
               JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
         );

         $covered = count($coverage['workers']);
         echo "      Warmup {$this->warmupDuration}s: {$covered}/{$serverWorkers} workers"
            . " · probe={$coverage['responses']}/{$coverage['requests']}"
            . " · responses={$traffic['responses']}"
            . " · failed={$traffic['failed']}"
            . " · write-failed={$traffic['write_failed']}"
            . " · connection-failed={$traffic['connection_failed']}\n";

         return true;
      }
      catch (Throwable $Throwable) {
         $partial = $Throwable instanceof WorkerWarmupFailure
            ? $Throwable->evidence
            : $coverage;
         $evidence = [
            'schema' => 'bootgly.worker-aware-warmup',
            'version' => 1,
            'validated' => false,
            'context' => $context,
            'error' => $Throwable->getMessage(),
            'coverage' => $partial,
         ];
         if (is_array($execution)) {
            $evidence['traffic_process'] = [
               'exit' => $execution['exit'],
               'timed_out' => $execution['timed_out'] ?? false,
               'state' => $execution['state'] ?? null,
               'signal' => $execution['signal'] ?? null,
            ];
         }

         try {
            $Artifacts->write(
               'evidence.json',
               json_encode(
                  $evidence,
                  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
               ) . "\n",
            );
         }
         catch (Throwable $EvidenceThrowable) {
            echo "      Warmup evidence could not be persisted: {$EvidenceThrowable->getMessage()}\n";
         }

         $rounds = is_array($partial['rounds'] ?? null) ? $partial['rounds'] : [];
         $last = $rounds === [] ? [] : $rounds[array_key_last($rounds)];
         $covered = count(is_array($last['workers'] ?? null) ? $last['workers'] : []);
         $responses = is_int($partial['responses'] ?? null) ? $partial['responses'] : 0;
         $failures = is_array($partial['failures'] ?? null)
            ? array_sum($partial['failures'])
            : 0;
         echo "      Warmup failed: {$Throwable->getMessage()}"
            . " ({$covered}/{$serverWorkers} workers, responses={$responses}, failures={$failures})\n";

         return false;
      }
      finally {
         $Artifacts->clean();
      }
   }
   private function command (
      Load $Load,
      string $token,
      int $serverWorkers,
      string $opponent,
      int $connections = 0,
      int $clientWorkers = 0,
   ): Result
   {
      $workerScript = __DIR__ . '/TCP_Client/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load load data from PHP file
      $loadData = include $Load->file;

      if (is_array($loadData) === false) {
         echo "      Preflight failed: invalid load data.\n";

         return new Result();
      }

      // @ Preflight with recovery retries — the previous load's 514-connection
      //   run may still be draining; a blocking server (nginx+PHP-FPM) needs a
      //   moment before the next route responds. Retry before declaring N/A.
      $preflight = $this->preflight($loadData);

      for ($try = 1; $try <= $this->preflightRetries && $preflight !== ''; $try++) {
         echo "      Preflight retry {$try}/{$this->preflightRetries} ({$preflight})\n";
         sleep($this->preflightRecovery);
         $preflight = $this->preflight($loadData);
      }

      if ($preflight !== '') {
         echo "      Preflight failed: {$preflight}\n";

         return new Result();
      }

      $JSON = json_encode($loadData, JSON_THROW_ON_ERROR);
      if (!$this->warmup(
         $loadData,
         $JSON,
         $token,
         $serverWorkers,
         $connections,
         $clientWorkers,
         $this->pipeline,
         $opponent,
         $Load->label,
      )) {
         return new Result();
      }

      $Artifacts = RunArtifacts::create('tcp-client-load');
      $input = $Artifacts->write('input.json', $JSON);
      $command = [
         PHP_BINARY,
         $workerScript,
         '--host=127.0.0.1',
         "--port={$this->port}",
         "--connections={$connections}",
         "--duration={$this->duration}",
         "--paths-file={$input}",
         "--workers={$clientWorkers}",
      ];
      if ($this->pipeline > 1) {
         $command[] = "--pipeline={$this->pipeline}";
      }

      try {
         $execution = $Artifacts->run($command, $this->duration + 30.0, 2.0);
         $output = $execution['exit'] === 0
            ? @file_get_contents($execution['stdout'])
            : false;

         return $this->parse($output === false ? '' : $output);
      }
      finally {
         $Artifacts->clean();
      }
   }

   /**
    * Verify one load returns the expected HTTP response before timing load.
    *
    * @param array<string,mixed> $load
    */
   private function preflight (array $load): string
   {
      $method = $load['method'] ?? 'GET';
      $paths = $load['paths'] ?? [];
      $expect = $load['expect'] ?? [];
      $strict = isset($load['expect']);

      if (is_string($method) === false || $method === '') {
         return 'load method must be a non-empty string';
      }

      if (is_array($paths) === false || $paths === []) {
         return 'load paths must be a non-empty array';
      }

      if (is_array($expect) === false) {
         $expect = [];
      }

      $expectedStatus = isset($expect['status']) ? (int) $expect['status'] : null;
      $contains = $expect['contains'] ?? [];

      if (is_string($contains)) {
         $contains = [$contains];
      }

      if (is_array($contains) === false) {
         $contains = [];
      }

      foreach ($paths as $path) {
         if (is_string($path) === false || $path === '') {
            return 'load path must be a non-empty string';
         }

         $response = $this->request($method, $path);

         if ($response['error'] !== '') {
            return "{$path}: {$response['error']}";
         }

         $status = $response['status'];

         if ($expectedStatus !== null && $status !== $expectedStatus) {
            return "{$path}: expected HTTP {$expectedStatus}, got HTTP {$status}";
         }

         if ($strict && $expectedStatus === null && ($status < 200 || $status >= 300)) {
            return "{$path}: expected HTTP 2xx, got HTTP {$status}";
         }

         foreach ($contains as $needle) {
            if (is_string($needle) === false || $needle === '') {
               continue;
            }

            if (str_contains($response['body'], $needle) === false) {
               return "{$path}: response body missing {$needle}";
            }
         }
      }

      return '';
   }

   /**
    * Send one short HTTP request for load preflight.
    *
    * @return array{status:int,body:string,error:string}
    */
   private function request (string $method, string $path): array
   {
      $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, $this->preflightTimeout);

      if (is_resource($socket) === false) {
         return [
            'status' => 0,
            'body' => '',
            'error' => $error !== '' ? $error : 'could not connect to server',
         ];
      }

      stream_set_timeout($socket, $this->preflightTimeout);
      fwrite($socket, "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nConnection: close\r\n\r\n");
      $raw = '';

      while (feof($socket) === false) {
         $chunk = fread($socket, 8192);

         if ($chunk === false) {
            fclose($socket);

            return [
               'status' => 0,
               'body' => '',
               'error' => 'could not read response',
            ];
         }

         if ($chunk === '') {
            $meta = stream_get_meta_data($socket);

            if (($meta['timed_out'] ?? false) === true) {
               fclose($socket);

               return [
                  'status' => 0,
                  'body' => '',
                  'error' => 'response timed out',
               ];
            }

            continue;
         }

         $raw .= $chunk;

         if ($this->complete($raw)) {
            break;
         }
      }

      fclose($socket);
      $parts = explode("\r\n\r\n", $raw, 2);
      $head = $parts[0] ?? '';
      $body = $parts[1] ?? '';
      $status = 0;

      if (preg_match('/^HTTP\/\S+\s+(\d{3})/m', $head, $matches) === 1) {
         $status = (int) $matches[1];
      }

      return [
         'status' => $status,
         'body' => $body,
         'error' => $status > 0 ? '' : 'invalid HTTP response',
      ];
   }

   /**
    * Check whether one raw HTTP response has the full declared body.
    */
   private function complete (string $raw): bool
   {
      $parts = explode("\r\n\r\n", $raw, 2);

      if (isset($parts[1]) === false) {
         return false;
      }

      if (preg_match('/\r\nContent-Length:\s*(\d+)\r\n/i', "\r\n" . $parts[0] . "\r\n", $matches) !== 1) {
         return false;
      }

      return strlen($parts[1]) >= (int) $matches[1];
   }
   private function parse (string $output): Result
   {
      /** @var array<string,mixed>|null $data */
      $data = json_decode($output, true);
      if (!\is_array($data)) {
         return new Result();
      }

      $statuses = \is_array($data['statuses'] ?? null) ? $data['statuses'] : [];
      $failures = \is_array($data['failures'] ?? null) ? $data['failures'] : [];
      $writeFailures = \is_array($data['write_failures'] ?? null) ? $data['write_failures'] : [];
      $counters = [
         'scheduled', 'sent', 'responses', 'informational', 'outstanding',
         'failed', 'write_failed', 'connection_failed', 'partial_writes',
      ];
      $valid = true;

      foreach ($counters as $counter) {
         $valid = $valid && \is_int($data[$counter] ?? null) && $data[$counter] >= 0;
      }
      foreach ([$statuses, $failures, $writeFailures] as $counts) {
         foreach ($counts as $count) {
            $valid = $valid && \is_int($count) && $count >= 0;
         }
      }
      $RPS = $data['rps'] ?? null;
      $validRPS = (\is_int($RPS) || \is_float($RPS))
         && \is_finite((float) $RPS)
         && (float) $RPS >= 0;

      $accounting = $valid
         && $validRPS
         && ($data['accounting'] ?? false) === true
         && $data['connection_failed'] === 0
         && $data['outstanding'] === 0
         && $data['scheduled'] === $data['sent'] + $data['write_failed']
         && $data['sent'] === $data['responses'] + $data['failed']
         && $data['failed'] === \array_sum($failures)
         && $data['write_failed'] === \array_sum($writeFailures)
         && $data['responses'] === \array_sum($statuses);

      return new Result(
         // ! Throughput is reportable only after the worker proves both
         //   request/response accounting equations.
         rps: $accounting ? (float) $RPS : null,
         latency: isset($data['latency']) ? (string) $data['latency'] : null,
         transfer: isset($data['transfer']) ? (string) $data['transfer'] : null,
         scheduled: isset($data['scheduled']) ? (int) $data['scheduled'] : null,
         sent: isset($data['sent']) ? (int) $data['sent'] : null,
         responses: isset($data['responses']) ? (int) $data['responses'] : null,
         informational: isset($data['informational']) ? (int) $data['informational'] : null,
         outstanding: isset($data['outstanding']) ? (int) $data['outstanding'] : null,
         failed: isset($data['failed']) ? (int) $data['failed'] : null,
         writeFailed: isset($data['write_failed']) ? (int) $data['write_failed'] : null,
         connectionFailed: isset($data['connection_failed']) ? (int) $data['connection_failed'] : null,
         partialWrites: isset($data['partial_writes']) ? (int) $data['partial_writes'] : null,
         accounting: $accounting,
         statuses: $statuses,
         failures: $failures,
         writeFailures: $writeFailures,
      );
   }
};
