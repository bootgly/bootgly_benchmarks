<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Client Runner
 * --------------------------------------------------------------------------
 * HTTP benchmark runner using Bootgly HTTP_Client_CLI as load generator.
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Competitor;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\ACI\Tests\Benchmark\Configs\Scenario;
use Bootgly\ACI\Tests\Benchmark\Configs\Scenarios;


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
    * Load scenarios from a directory of .php files.
    *
    * @param string $scenariosDir Absolute path.
    */
   public function load (string $scenariosDir): void
   {
      $this->scenarios = Scenarios::loadPhp($scenariosDir);
   }
   /**
    * Resolve the client worker count.
    * When workers = 0 (auto), defaults to nproc / 2 (similar to wrk threads).
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
      $baseWorkers = $this->competitors[0]->workers ?? $defaultWorkers;
      $workersStep = $Configs->vary['server-workers'] ?? 0;
      $connectionsStep = $Configs->vary['connections'] ?? 0;
      $clientWorkersStep = $Configs->vary['client-workers'] ?? 0;

      if ($workersStep > 0) {
         $lo = max(1, $baseWorkers - $workersStep);
         $hi = $baseWorkers + $workersStep;
         $workersDisplay = "{$lo}-{$hi}";
      } else {
         $workersDisplay = (string) $baseWorkers;
      }

      if ($connectionsStep > 0) {
         $lo = max(1, $this->connections - $connectionsStep);
         $hi = $this->connections + $connectionsStep;
         $connectionsDisplay = "{$lo}-{$hi}";
      } else {
         $connectionsDisplay = (string) $this->connections;
      }

      $baseClientWorkers = $this->resolveClientWorkers();
      if ($clientWorkersStep > 0) {
         $lo = max(1, $baseClientWorkers - $clientWorkersStep);
         $hi = $baseClientWorkers + $clientWorkersStep;
         $clientWorkersDisplay = "{$lo}-{$hi}";
      } else {
         $clientWorkersDisplay = (string) $baseClientWorkers;
      }

      $clientFlags = "-c{$connectionsDisplay} -d{$this->duration}s";
      $clientFlags .= " -w{$clientWorkersDisplay}";
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
      $rounds = $this->computeRounds($Configs->vary);

      // @ Install SIGINT handler to stop server on CTRL+C
      $activeCompetitor = null;
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, function () use (&$activeCompetitor) {
         if ($activeCompetitor !== null) {
            $this->stopServer($activeCompetitor);
         }
         exit(130);
      });

      // @ Pre-filter scenarios for counting
      $filteredScenarios = [];
      foreach ($this->scenarios as $index => $Scenario) {
         if ($Configs->scenarios !== null && !in_array($index + 1, $Configs->scenarios)) {
            continue;
         }
         $filteredScenarios[$index] = $Scenario;
      }
      $totalScenarios = count($filteredScenarios);

      foreach ($this->competitors as $Competitor) {
         // ? Filter (case-insensitive)
         if ($Configs->competitors !== null && !in_array(strtolower($Competitor->name), array_map(strtolower(...), $Configs->competitors))) {
            continue;
         }

         // @ Best results across all rounds
         $bestResults = [];
         $roundCount = count($rounds);

         // @ Rounds summary (vary mode)
         if ($roundCount > 1) {
            // @ Build labels for varied dimensions only
            $varyKeys = [];
            if (($Configs->vary['server-workers'] ?? 0) > 0) $varyKeys[] = ['key' => 'workers', 'suffix' => 'sw'];
            if (($Configs->vary['connections'] ?? 0) > 0)    $varyKeys[] = ['key' => 'connections', 'suffix' => 'c'];
            if (($Configs->vary['client-workers'] ?? 0) > 0) $varyKeys[] = ['key' => 'client-workers', 'suffix' => 'cw'];

            $details = implode(', ', array_map(
               fn ($r) => implode('/', array_map(
                  fn ($k) => "{$r[$k['key']]}{$k['suffix']}",
                  $varyKeys
               )),
               $rounds
            ));
            echo "  {$BOLD}{$BLUE}▸ {$Competitor->name}: {$roundCount} rounds ({$details}){$RESET}\n\n";
         }

         foreach ($rounds as $roundIndex => $round) {
            // @ Apply round parameters
            $roundConnections = $round['connections'];
            $roundWorkers = $round['workers'];
            $roundClientWorkers = $round['client-workers'];

            // @ Round header (vary mode)
            if ($roundCount > 1) {
               $ri = $roundIndex + 1;
               $parts = [];
               if (($Configs->vary['server-workers'] ?? 0) > 0) $parts[] = "{$roundWorkers} server workers";
               if (($Configs->vary['connections'] ?? 0) > 0)    $parts[] = "{$roundConnections} connections";
               if (($Configs->vary['client-workers'] ?? 0) > 0) $parts[] = "{$roundClientWorkers} client workers";
               $desc = implode(', ', $parts);
               echo "  {$BOLD}{$BLUE}▸ Round {$ri}/{$roundCount} ({$desc}){$RESET}\n";
            }

            // @ Kill port
            $this->killPort();

            // @ Start server
            $activeCompetitor = $Competitor;
            echo "  {$BOLD}{$BLUE}▸ Starting {$Competitor->name}...{$RESET}\n";
            $this->startServer($Competitor, $roundWorkers);

            // @ Wait for server readiness
            if ( !$this->waitForServer() ) {
               echo "    {$RED}{$Competitor->name} failed to start!{$RESET}\n\n";
               $this->stopServer($Competitor);
               continue;
            }

            echo "    {$GREEN}{$Competitor->name} ready (port {$this->port}).{$RESET}\n";

            // @ Warmup
            echo "    {$DIM}Warming up ({$this->warmupDuration}s)...{$RESET}\n";
            $this->warmup();
            // @ Let server recover from warmup connection cleanup
            sleep(2);

            // @ Run scenarios
            $scenarioNum = 0;
            $prevGroup = '';

            foreach ($this->scenarios as $index => $Scenario) {
               // ? Filter by scenario index (1-based)
               if ($Configs->scenarios !== null && !in_array($index + 1, $Configs->scenarios)) {
                  continue;
               }

               // ? Skip if scenario restricts competitors
               if (
                  $Scenario->competitors !== 'all'
                  && !in_array($Competitor->name, explode(',', $Scenario->competitors))
               ) {
                  continue;
               }

               // @ Group header
               if ($Scenario->group !== '' && $Scenario->group !== $prevGroup) {
                  echo "    {$BOLD}{$Scenario->group}{$RESET}\n";
                  $prevGroup = $Scenario->group;
               }

               $scenarioNum++;

               $Result = $this->command($Scenario, $roundConnections, $roundClientWorkers);

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

               echo "    {$DIM}[{$scenarioNum}/{$totalScenarios}]{$RESET} {$Scenario->label}...  {$rps}{$transfer}{$latency}\n";

               // @ Keep best RPS per scenario
               $label = $Scenario->label;
               if (
                  !isset($bestResults[$label])
                  || ($Result->rps !== null && ($bestResults[$label]->rps === null || $Result->rps > $bestResults[$label]->rps))
               ) {
                  $bestResults[$label] = $Result;
               }
            }

            echo "\n";

            // @ Stop server
            $this->stopServer($Competitor);
            $activeCompetitor = null;
         }

         $results[$Competitor->name] = $bestResults;
      }

      return $results;
   }

   // # Server lifecycle
   private function killPort (): void
   {
      exec("lsof -ti :{$this->port} 2>/dev/null | xargs kill -9 2>/dev/null");
      usleep(500_000);
   }
   private function startServer (Competitor $Competitor, int $workers): void
   {
      $script = $Competitor->script;
      putenv("BOOTGLY_WORKERS={$workers}");
      exec("BOOTGLY_WORKERS={$workers} php {$script} start > /dev/null 2>&1 &");
   }
   private function stopServer (Competitor $Competitor): void
   {
      $script = $Competitor->script;
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

   /**
    * Compute round configurations based on vary settings.
    *
    * @param array<string,int> $vary
    *
    * @return array<array{workers: int, connections: int, client-workers: int}>
    */
   private function computeRounds (array $vary): array
   {
      $nproc = (int) (exec('nproc 2>/dev/null') ?: 1);
      $baseWorkers = $this->competitors[0]->workers ?? max(1, (int) ($nproc / 2));
      $baseConnections = $this->connections;
      $baseClientWorkers = $this->resolveClientWorkers();

      $workersStep = $vary['server-workers'] ?? 0;
      $connectionsStep = $vary['connections'] ?? 0;
      $clientWorkersStep = $vary['client-workers'] ?? 0;

      // @ Generate values per dimension
      $workersValues = $workersStep > 0
         ? array_keys(array_flip([max(1, $baseWorkers - $workersStep), $baseWorkers, $baseWorkers + $workersStep]))
         : [$baseWorkers];

      $connectionsValues = $connectionsStep > 0
         ? array_keys(array_flip([max(1, $baseConnections - $connectionsStep), $baseConnections, $baseConnections + $connectionsStep]))
         : [$baseConnections];

      $clientWorkersValues = $clientWorkersStep > 0
         ? array_keys(array_flip([max(1, $baseClientWorkers - $clientWorkersStep), $baseClientWorkers, $baseClientWorkers + $clientWorkersStep]))
         : [$baseClientWorkers];

      // @ Cartesian product
      $rounds = [];
      foreach ($workersValues as $w) {
         foreach ($connectionsValues as $c) {
            foreach ($clientWorkersValues as $cw) {
               $rounds[] = ['workers' => $w, 'connections' => $c, 'client-workers' => $cw];
            }
         }
      }

      return $rounds;
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
   private function command (Scenario $Scenario, int $connections = 0, int $clientWorkers = 0): Result
   {
      $workerScript = __DIR__ . '/HTTP_Client/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load scenario data from PHP file
      $scenarioData = include $Scenario->file;

      // @ Write scenario paths to temp file
      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_scenario_');
      if ($tmpFile === false) {
         return new Result();
      }

      file_put_contents($tmpFile, json_encode($scenarioData));

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
