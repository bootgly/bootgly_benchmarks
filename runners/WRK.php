<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Wrk Runner
 * --------------------------------------------------------------------------
 * HTTP benchmark runner using the wrk load testing tool.
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
   threads: 10,
   connections: 514,
   duration: '10s',
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'wrk';

   // * Config
   // # wrk
   public int $port;
   public int $threads;
   public int $connections;
   public string $duration;
   // # warmup
   public string $warmupDuration = '2s';
   public int $warmupThreads = 2;
   public int $warmupConnections = 64;
   // # server readiness
   public int $readyTimeout = 10;


   public function __construct (
      int $port = 8080,
      int $threads = 10,
      int $connections = 514,
      string $duration = '10s',
   )
   {
      $this->port = $port;
      $this->threads = $threads;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'HTTP server stopped after benchmark run.';
   }

   public function configure (array $options): void
   {
      if (isset($options['threads'])) {
         $this->threads = (int) $options['threads'];
      }
      if (isset($options['server-workers'])) {
         $serverWorkers = (int) $options['server-workers'];
         foreach ($this->competitors as $Competitor) {
            $Competitor->workers = $serverWorkers;
         }
      }
   }
   public function options (): array
   {
      return [
         '--threads=N' => 'Number of wrk threads (default: 10)',
      ];
   }

   /**
    * Load scenarios from a directory of .lua files.
    *
    * @param string $scenariosDir Absolute path.
    */
   public function load (string $scenariosDir): void
   {
      $this->scenarios = Scenarios::load($scenariosDir);
   }
   /**
    * @return array<string,array<string,string>>
    */
   public function banner (Configs $Configs): array
   {
      $sections = [];

      // # Dependencies
      $wrkOutput = [];
      exec('wrk -v 2>&1 | head -1', $wrkOutput);
      $wrk = trim($wrkOutput[0] ?? '');
      if ($wrk !== '') {
         $sections['Dependencies'] = ['wrk' => $wrk];
      }

      // # Configuration
      $defaultWorkers = max(1, (int) (exec('nproc 2>/dev/null') ?: 1) / 2);
      $baseWorkers = $this->competitors[0]->workers ?? $defaultWorkers;
      $workersStep = $Configs->vary['server-workers'] ?? 0;

      if ($workersStep > 0) {
         $lo = max(1, $baseWorkers - $workersStep);
         $hi = $baseWorkers + $workersStep;
         $workersDisplay = "{$lo}-{$hi}";
      } else {
         $workersDisplay = (string) $baseWorkers;
      }

      $sections['Configuration'] = [
         'wrk'     => "-t{$this->threads} -c{$this->connections} -d{$this->duration}",
         'Warmup'  => "-t{$this->warmupThreads} -c{$this->warmupConnections} -d{$this->warmupDuration}",
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
         // ? Filter (slug-normalized, e.g. "Swoole (Base)" matches "swoole-base")
         if ($Configs->competitors !== null && !in_array(Configs::slug($Competitor->name), array_map(Configs::slug(...), $Configs->competitors))) {
            continue;
         }

         // @ Best results across all rounds
         $bestResults = [];
         $roundCount = count($rounds);

         // @ Rounds summary (vary mode)
         if ($roundCount > 1) {
            $details = implode(', ', array_map(
               fn ($r) => "{$r['workers']}w/{$r['threads']}t",
               $rounds
            ));
            echo "  {$BOLD}{$BLUE}▸ {$Competitor->name}: {$roundCount} rounds ({$details}){$RESET}\n\n";
         }

         foreach ($rounds as $roundIndex => $round) {
            // @ Apply round parameters
            $roundThreads = $round['threads'];
            $roundWorkers = $round['workers'];

            // @ Round header (vary mode)
            if ($roundCount > 1) {
               $ri = $roundIndex + 1;
               echo "  {$BOLD}{$BLUE}▸ Round {$ri}/{$roundCount} ({$roundWorkers} workers, {$roundThreads} wrk threads){$RESET}\n";
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
            echo "    {$DIM}Warming up ({$this->warmupDuration})...{$RESET}\n";
            $this->warmup();

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

               $Result = $this->command($Scenario, $roundThreads);

               // @ Real-time result
               $rps = $Result->rps !== null
                  ? "{$BOLD}{$GREEN}" . number_format((int) $Result->rps) . " req/s{$RESET}"
                  : "{$RED}N/A{$RESET}";
               $latency = $Result->latency !== null
                  ? "  {$DIM}({$Result->latency}){$RESET}"
                  : '';

               echo "    {$DIM}[{$scenarioNum}/{$totalScenarios}]{$RESET} {$Scenario->label}...  {$rps}{$latency}\n";

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

   /**
    * Compute round configurations based on vary settings.
    *
    * @param array<string,int> $vary
    *
    * @return array<array{workers: int, threads: int}>
    */
   private function computeRounds (array $vary): array
   {
      $nproc = (int) (exec('nproc 2>/dev/null') ?: 1);
      $baseWorkers = $this->competitors[0]->workers ?? max(1, (int) ($nproc / 2));
      $baseThreads = $this->threads;

      $workersStep = $vary['server-workers'] ?? 0;
      $threadsStep = $vary['threads'] ?? 0;

      // @ Generate values per dimension
      $workersValues = $workersStep > 0
         ? array_keys(array_flip([max(1, $baseWorkers - $workersStep), $baseWorkers, $baseWorkers + $workersStep]))
         : [$baseWorkers];

      $threadsValues = $threadsStep > 0
         ? array_keys(array_flip([max(1, $baseThreads - $threadsStep), $baseThreads, $baseThreads + $threadsStep]))
         : [$baseThreads];

      // @ Cartesian product
      $rounds = [];
      foreach ($workersValues as $w) {
         foreach ($threadsValues as $t) {
            $rounds[] = ['workers' => $w, 'threads' => $t];
         }
      }

      return $rounds;
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

   // # Benchmark execution
   private function warmup (): void
   {
      $url = "http://127.0.0.1:{$this->port}/";

      exec(
         "wrk -t{$this->warmupThreads} -c{$this->warmupConnections}"
         . " -d{$this->warmupDuration} {$url} > /dev/null 2>&1"
      );
   }
   private function command (Scenario $Scenario, int $threads = 0): Result
   {
      $threads = $threads > 0 ? $threads : $this->threads;
      $url = "http://127.0.0.1:{$this->port}/";

      $command = "wrk -t{$threads} -c{$this->connections}"
         . " -d{$this->duration} -s {$Scenario->file} {$url} 2>&1";

      $lines = [];
      exec($command, $lines);
      $output = implode("\n", $lines);

      return $this->parse($output);
   }
   private function parse (string $output): Result
   {
      $rps = null;
      $latency = null;
      $transfer = null;

      // @ Parse Requests/sec
      if (preg_match('/Requests\/sec:\s+([\d.]+)/', $output, $matches)) {
         $rps = (float) $matches[1];
      }

      // @ Parse Latency (avg)
      if (preg_match('/Latency\s+([\d.]+\w+)/', $output, $matches)) {
         $latency = $matches[1];
      }

      // @ Parse Transfer/sec
      if (preg_match('/Transfer\/sec:\s+([\d.]+\w+)/', $output, $matches)) {
         $transfer = $matches[1];
      }

      return new Result(
         rps: $rps,
         latency: $latency,
         transfer: $transfer,
      );
   }
};
