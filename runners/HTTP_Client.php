<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Client Runner
 * --------------------------------------------------------------------------
 * HTTP benchmark runner using Bootgly HTTP_Client_CLI as load generator.
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

   public protected(set) string $name = 'http_client';

   // * Config
   public int $port;
   public int $connections;
   public int $duration;
   public int $workers = 0;
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
      if (isset($options['warmup'])) {
         $this->warmupDuration = (int) $options['warmup'];
      }
      if ($this->warmupDuration < 1) {
         throw new InvalidArgumentException('--warmup must be a positive number of seconds.');
      }

      $this->workers = $this->resolveClientWorkers();
      $this->meta['connections'] = $this->connections;
      $this->meta['duration'] = $this->duration;
      $this->meta['client-workers'] = $this->workers;
      $this->meta['warmup'] = $this->warmupDuration;
   }
   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of TCP connections (default: 514)',
         '--duration=N'       => 'Benchmark duration in seconds (default: 10)',
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
            'Engine'         => 'Bootgly HTTP_Client_CLI',
            'Client workers' => (string) $this->resolveClientWorkers(),
            'Connections'    => (string) $this->connections,
            'Duration'       => "{$this->duration} s",
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

            echo "    {$DIM}[{$loadNum}/{$totalLoads}]{$RESET} {$Load->label}...  {$rps}{$transfer}{$latency}\n";

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
      $this->ServerArtifacts = RunArtifacts::create('http-client-server');
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
      $Artifacts = RunArtifacts::create('http-client-server-stop');
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

   // # Benchmark execution
   /**
    * Run sustained selected-load traffic through the same HTTP client engine
    * used for measurement, then use worker coverage as the final preflight.
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
      string $opponent,
      string $label,
   ): bool
   {
      $workerScript = __DIR__ . '/HTTP_Client/worker.php';
      $Artifacts = RunArtifacts::create('http-client-warmup');
      $input = $Artifacts->write('input.json', $JSON);
      $Warmup = new WorkerWarmup('127.0.0.1', $this->port);
      $context = [
         'opponent' => $opponent,
         'label' => $label,
         'load_input_sha256' => 'sha256:' . hash('sha256', $JSON),
         'requested_warmup_duration' => $this->warmupDuration,
         'server_workers' => $serverWorkers,
         'client_workers' => $clientWorkers,
         'connections' => $connections,
         'pipeline' => 1,
         'db_pool_max' => isset($this->meta['db-pool-max'])
            ? (int) $this->meta['db-pool-max']
            : null,
      ];
      $coverage = [];
      $execution = null;

      try {
         $execution = $Artifacts->run([
            PHP_BINARY,
            $workerScript,
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$connections}",
            "--duration={$this->warmupDuration}",
            "--paths-file={$input}",
            "--workers={$clientWorkers}",
         ], $this->warmupDuration + 30.0, 2.0);
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
      $workerScript = __DIR__ . '/HTTP_Client/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load load data from PHP file
      $loadData = include $Load->file;

      if (is_array($loadData) === false) {
         echo "      Warmup failed: invalid load data.\n";

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
         $opponent,
         $Load->label,
      )) {
         return new Result();
      }

      $Artifacts = RunArtifacts::create('http-client-load');
      $input = $Artifacts->write('input.json', $JSON);

      try {
         $execution = $Artifacts->run([
            PHP_BINARY,
            $workerScript,
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$connections}",
            "--duration={$this->duration}",
            "--paths-file={$input}",
            "--workers={$clientWorkers}",
         ], $this->duration + 30.0, 2.0);
         $output = $execution['exit'] === 0
            ? @file_get_contents($execution['stdout'])
            : false;

         return $this->parse($output === false ? '' : $output);
      }
      finally {
         $Artifacts->clean();
      }
   }
   private function parse (string $output): Result
   {
      /** @var array{rps?: float, latency?: string|null, transfer?: string|null}|null $data */
      $data = json_decode($output, true);
      if (!\is_array($data)) {
         return new Result();
      }

      return new Result(
         rps: isset($data['rps']) ? (float) $data['rps'] : null,
         latency: isset($data['latency']) ? (string) $data['latency'] : null,
         transfer: isset($data['transfer']) ? (string) $data['transfer'] : null,
      );
   }
};
