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
   public int $warmupDuration = 2;
   public int $warmupConnections = 64;
   // # server readiness
   public int $readyTimeout = 10;

   // * Metadata
   // # opponent start output (stdout+stderr) — surfaced when readiness fails
   private string $serverLog = '';


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
      $sections = [];

      // # Configuration
      $defaultWorkers = max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2));
      $workersDisplay = (string) ($this->opponents[0]->workers ?? $defaultWorkers);

      $clientFlags = "-c{$this->connections} -d{$this->duration}s";
      $clientFlags .= ' -w' . $this->resolveClientWorkers();
      $sections['Configuration'] = [
         'Client'  => "Bootgly HTTP_Client_CLI {$clientFlags}",
         'Warmup'  => "-c{$this->warmupConnections} -d{$this->warmupDuration}s",
         'Port'    => (string) $this->port,
         'Workers' => $workersDisplay,
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
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, function () use (&$activeOpponent) {
         if ($activeOpponent !== null) {
            $this->stopServer($activeOpponent);
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

         // ! Server worker count — set by Runner::apply (or auto: nproc / 2)
         $workers = $Opponent->workers
            ?? max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

         // @ Kill port
         $this->killPort();

         // @ Start server
         $activeOpponent = $Opponent;
         echo "  {$BOLD}{$BLUE}▸ Starting {$Opponent->name}...{$RESET}\n";
         $this->startServer($Opponent, $workers);

         // @ Wait for server readiness
         if ( !$this->waitForServer() ) {
            echo "    {$RED}{$Opponent->name} failed to start!{$RESET}\n";
            // ? Surface the opponent's own start output (e.g. capability guard message).
            $output = is_file($this->serverLog) ? trim((string) file_get_contents($this->serverLog)) : '';
            foreach ($output === '' ? [] : array_slice(explode("\n", $output), -6) as $line) {
               echo "    {$DIM}{$line}{$RESET}\n";
            }
            echo "\n";
            $this->stopServer($Opponent);
            continue;
         }

         echo "    {$GREEN}{$Opponent->name} ready (port {$this->port}).{$RESET}\n";

         // @ Warmup
         echo "    {$DIM}Warming up ({$this->warmupDuration}s)...{$RESET}\n";
         $this->warmup();
         // @ Let server recover from warmup connection cleanup
         sleep(2);

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

            $Result = $this->command($Load, $this->connections, $this->workers);

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

         // @ Stop server
         $this->stopServer($Opponent);
         $activeOpponent = null;

         $results[$Opponent->name] = $loadResults;
      }

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
      // ! Capture start output — surfaced by the caller when readiness fails.
      $this->serverLog = sys_get_temp_dir() . '/bootgly-benchmark-server.log';
      @unlink($this->serverLog);
      exec("BOOTGLY_WORKERS={$workers} php {$script} start > {$this->serverLog} 2>&1 &");
   }
   private function stopServer (Opponent $Opponent): void
   {
      $script = $Opponent->script;
      exec("php {$script} stop > /dev/null 2>&1");

      sleep(1);
      $this->killPort();
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

      return false;
   }

   // # Benchmark execution
   private function warmup (): void
   {
      // @ Run worker.php with reduced load for warmup
      $workerScript = __DIR__ . '/HTTP_Client/worker.php';

      // @ Create a simple warmup paths file (just GET /)
      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_warmup_');
      if ($tmpFile === false) return;

      file_put_contents($tmpFile, json_encode([
         'method' => 'GET',
         'paths'  => ['/'],
      ]));

      exec(
         "php {$workerScript}"
         . " --host=127.0.0.1"
         . " --port={$this->port}"
         . " --connections={$this->warmupConnections}"
         . " --duration={$this->warmupDuration}"
         . " --paths-file={$tmpFile}"
         . " --workers={$this->resolveClientWorkers()}"
         . " > /dev/null 2>&1"
      );

      @unlink($tmpFile);
   }
   private function command (Load $Load, int $connections = 0, int $clientWorkers = 0): Result
   {
      $workerScript = __DIR__ . '/HTTP_Client/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load load data from PHP file
      $loadData = include $Load->file;

      // @ Write load paths to temp file
      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_load_');
      if ($tmpFile === false) {
         return new Result();
      }

      file_put_contents($tmpFile, json_encode($loadData));

      // @ Run worker subprocess
      $output = [];
      $cmd = "php {$workerScript}"
         . " --host=127.0.0.1"
         . " --port={$this->port}"
         . " --connections={$connections}"
         . " --duration={$this->duration}"
         . " --paths-file={$tmpFile}"
         . " --workers={$clientWorkers}";
      $cmd .= " 2>/dev/null";

      exec($cmd, $output);

      @unlink($tmpFile);

      // @ Parse JSON output
      $json = implode('', $output);

      return $this->parse($json);
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
