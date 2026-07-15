<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — TCP_Raw Runner
 * --------------------------------------------------------------------------
 * Raw TCP benchmark runner using Bootgly TCP_Client_CLI as load generator.
 * Sends raw messages (defined by load) and counts responses by delimiter.
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

require_once __DIR__ . '/RunArtifacts.php';


return new class (
   port: 8080,
   connections: 514,
   duration: 10,
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'tcp_raw';

   // * Config
   public int $port;
   public int $connections;
   public int $duration;
   public int $workers = 0;
   // # warmup
   public int $warmupDuration = 2;
   public int $warmupConnections = 64;
   // # server readiness
   public int $readyTimeout = 10;

   private ?RunArtifacts $ServerArtifacts = null;
   private ?RunProcess $ServerProcess = null;


   public function __construct (
      int $port = 8080,
      int $connections = 514,
      int $duration = 10,
   )
   {
      $this->port = $port;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'TCP server stopped after benchmark run.';
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

      $this->workers = $this->resolveClientWorkers();
      $this->meta['connections'] = $this->connections;
      $this->meta['duration'] = $this->duration;
      $this->meta['client-workers'] = $this->workers;
   }
   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of TCP connections (default: 514)',
         '--duration=N'       => 'Benchmark duration in seconds (default: 10)',
      ];
   }

   /**
    * Load loads from a directory of .php files.
    */
   public function load (string $loadsDir): void
   {
      $this->loads = Loads::loadPhp($loadsDir);
   }

   /**
    * Resolve the client worker count.
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
            'Warmup'         => "{$this->warmupConnections} connections · {$this->warmupDuration} s",
         ],
         'Server' => [
            'Port' => (string) $this->port,
         ],
      ];

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
         // ? Filter
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         putenv('BENCHMARK_PROFILE_SCOPE=' . Configs::slug($Opponent->name));

         // ! Server worker count — set by Runner::apply (or auto: nproc / 2)
         $workers = $Opponent->workers
            ?? max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

         // @ Kill port
         $this->killPort();

         // @ Start server
         $activeOpponent = $Opponent;
         $stopping = false;

         try {
         echo "  {$BOLD}{$BLUE}▸ Starting {$Opponent->name}...{$RESET}\n";
         $this->startServer($Opponent, $workers);

         // @ Wait for server readiness
         if ( !$this->waitForServer() ) {
            echo "    {$RED}{$Opponent->name} failed to start!{$RESET}\n\n";
            $stopping = true;
            $this->stopServer($Opponent);
            $activeOpponent = null;
            $stopping = false;
            if ($interrupted) {
               exit(130);
            }
            continue;
         }

         echo "    {$GREEN}{$Opponent->name} ready (port {$this->port}).{$RESET}\n";

         // @ Warmup
         echo "    {$DIM}Warming up ({$this->warmupDuration}s)...{$RESET}\n";
         $this->warmup();
         sleep(2);

         // @@ Run loads
         $loadResults = [];
         $loadNum = 0;
         $prevGroup = '';

         foreach ($this->loads as $index => $Load) {
            if ($Configs->loads !== null && !in_array($index + 1, $Configs->loads)) {
               continue;
            }

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

            $Result = $this->command($Load, $this->connections, $this->workers);

            // @ Real-time result
            $rps = $Result->rps !== null
               ? "{$BOLD}{$GREEN}" . number_format((int) $Result->rps) . " msg/s{$RESET}"
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

         // @ Stop server
         $stopping = true;
         $this->stopServer($Opponent);
         $activeOpponent = null;
         $stopping = false;
         if ($interrupted) {
            exit(130);
         }

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
   private function startServer (Opponent $Opponent, int $workers): void
   {
      $script = $Opponent->script;
      putenv("BOOTGLY_WORKERS={$workers}");
      $this->ServerArtifacts?->clean();
      $this->ServerArtifacts = RunArtifacts::create('tcp-raw-server');
      $this->ServerProcess = $this->ServerArtifacts->start(
         [PHP_BINARY, $script, 'start'],
         [
            'BENCHMARK_SERVER_DIR' => $this->ServerArtifacts->directory,
            'BOOTGLY_WORKERS' => (string) $workers,
         ]
      );
   }
   private function stopServer (Opponent $Opponent): void
   {
      $script = $Opponent->script;
      $Artifacts = RunArtifacts::create('tcp-raw-server-stop');

      try {
         $Artifacts->start([PHP_BINARY, $script, 'stop'])->wait(10.0, 2.0);
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
   private function waitForServer (): bool
   {
      $deadline = time() + $this->readyTimeout;

      while (time() < $deadline) {
         $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);

         if ($socket) {
            fclose($socket);
            return true;
         }

         usleep(250_000);
      }

      if ($this->ServerProcess !== null && !$this->ServerProcess->check()) {
         $this->ServerProcess->wait();
      }

      return false;
   }

   // # Benchmark execution
   private function warmup (): void
   {
      $workerScript = __DIR__ . '/TCP_Raw/worker.php';
      $Artifacts = RunArtifacts::create('tcp-raw-warmup');
      $JSON = json_encode([
         'message'   => "PING\n",
         'delimiter' => "\n",
      ], JSON_THROW_ON_ERROR);
      $input = $Artifacts->write('input.json', $JSON);

      try {
         $Artifacts->run([
            PHP_BINARY,
            $workerScript,
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$this->warmupConnections}",
            "--duration={$this->warmupDuration}",
            "--load-file={$input}",
            '--workers=' . $this->resolveClientWorkers(),
         ], $this->warmupDuration + 30.0, 2.0);
      }
      finally {
         $Artifacts->clean();
      }
   }

   private function command (Load $Load, int $connections = 0, int $clientWorkers = 0): Result
   {
      $workerScript = __DIR__ . '/TCP_Raw/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load load data from PHP file
      $loadData = include $Load->file;

      $Artifacts = RunArtifacts::create('tcp-raw-load');
      $JSON = json_encode($loadData, JSON_THROW_ON_ERROR);
      $input = $Artifacts->write('input.json', $JSON);

      try {
         $execution = $Artifacts->run([
            PHP_BINARY,
            $workerScript,
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$connections}",
            "--duration={$this->duration}",
            "--load-file={$input}",
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
