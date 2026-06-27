<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — WS_Raw Runner
 * --------------------------------------------------------------------------
 * WebSocket server benchmark runner using Bootgly WS_Client_CLI as the load
 * generator. Opens N persistent WebSocket connections and drives one of three
 * closed-loop scenarios per load (echo / broadcast / connect), counting
 * messages (or handshakes) per second.
 * --------------------------------------------------------------------------
 */

use function random_bytes;

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Loads;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;


return new class (
   port: 8085,
   connections: 514,
   duration: 10,
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'ws_raw';
   public string $metric = 'msg/s';

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
      int $port = 8085,
      int $connections = 514,
      int $duration = 10,
   )
   {
      $this->port = $port;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'WebSocket server stopped after benchmark run.';
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
      if (isset($options['server-workers'])) {
         $serverWorkers = (int) $options['server-workers'];
         foreach ($this->opponents as $Opponent) {
            $Opponent->workers = $serverWorkers;
         }
      }

      // ! Materialise the auto client-worker count so $this->meta captures the
      //   resolved value (nproc / 2) instead of the `0` auto sentinel.
      $this->workers = $this->resolveClientWorkers();

      // @ Surface the active runner config into the `.marks` Config header so
      //   chart.py can use connections (or workers) as a sweep axis — without
      //   these keys every run records the same Config and the X axis collapses.
      $this->meta['connections']    = $this->connections;
      $this->meta['duration']       = $this->duration;
      $this->meta['client-workers'] = $this->workers;
      $this->meta['server-workers'] = $this->opponents[0]->workers
         ?? max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));
   }
   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of WebSocket connections (default: 514)',
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
    * @return array<string,array<string,string>>
    */
   public function banner (Configs $Configs): array
   {
      $sections = [];

      $defaultWorkers = max(1, (int) ((int)(exec('nproc 2>/dev/null') ?: 1) / 2));
      $baseWorkers = $this->opponents[0]->workers ?? $defaultWorkers;
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

      $clientFlags = "-c{$connectionsDisplay} -d{$this->duration}s -w{$clientWorkersDisplay}";

      $sections['Configuration'] = [
         'Client'  => "Bootgly WS_Client_CLI {$clientFlags}",
         'Warmup'  => "-c{$this->warmupConnections} -d{$this->warmupDuration}s",
         'Port'    => (string) $this->port,
         'Workers' => $workersDisplay,
      ];

      return $sections;
   }

   // # Workers
   private function resolveClientWorkers (): int
   {
      if ($this->workers > 0) {
         return $this->workers;
      }

      $nproc = (int) (exec('nproc 2>/dev/null') ?: 1);
      $cpuWorkers = max(1, (int) ($nproc / 2));
      $fdWorkers  = (int) max(1, ceil($this->connections / 1000));

      return max($cpuWorkers, $fdWorkers);
   }

   /**
    * @param array<string,int> $vary
    * @return array<array{workers: int, connections: int, client-workers: int}>
    */
   private function computeRounds (array $vary): array
   {
      $nproc = (int) (exec('nproc 2>/dev/null') ?: 1);
      $baseWorkers = $this->opponents[0]->workers ?? max(1, (int) ($nproc / 2));
      $baseConnections = $this->connections;
      $baseClientWorkers = $this->resolveClientWorkers();

      $workersStep = $vary['server-workers'] ?? 0;
      $connectionsStep = $vary['connections'] ?? 0;
      $clientWorkersStep = $vary['client-workers'] ?? 0;

      $workersValues = $workersStep > 0
         ? array_keys(array_flip([max(1, $baseWorkers - $workersStep), $baseWorkers, $baseWorkers + $workersStep]))
         : [$baseWorkers];

      $connectionsValues = $connectionsStep > 0
         ? array_keys(array_flip([max(1, $baseConnections - $connectionsStep), $baseConnections, $baseConnections + $connectionsStep]))
         : [$baseConnections];

      $clientWorkersValues = $clientWorkersStep > 0
         ? array_keys(array_flip([max(1, $baseClientWorkers - $clientWorkersStep), $baseClientWorkers, $baseClientWorkers + $clientWorkersStep]))
         : [$baseClientWorkers];

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

   public function run (Configs $Configs): array
   {
      $BLUE   = self::wrap(self::_BLUE_FOREGROUND);
      $GREEN  = self::wrap(self::_GREEN_FOREGROUND);
      $RED    = self::wrap(self::_RED_FOREGROUND);
      $BOLD   = self::wrap(self::_BOLD_STYLE);
      $DIM    = self::wrap(self::_DIM_STYLE);
      $RESET  = self::_RESET_FORMAT;

      $results = [];
      $rounds = $this->computeRounds($Configs->vary);

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
         // ? Filter
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         $bestResults = [];
         $roundCount = count($rounds);

         if ($roundCount > 1) {
            $varyKeys = [];
            if (($Configs->vary['server-workers'] ?? 0) > 0) $varyKeys[] = ['key' => 'workers',        'suffix' => 'sw'];
            if (($Configs->vary['connections'] ?? 0) > 0)    $varyKeys[] = ['key' => 'connections',    'suffix' => 'c'];
            if (($Configs->vary['client-workers'] ?? 0) > 0) $varyKeys[] = ['key' => 'client-workers', 'suffix' => 'cw'];

            $details = implode(', ', array_map(
               fn ($r) => implode('/', array_map(
                  fn ($k) => "{$r[$k['key']]}{$k['suffix']}",
                  $varyKeys
               )),
               $rounds
            ));
            echo "  {$BOLD}{$BLUE}▸ {$Opponent->name}: {$roundCount} rounds ({$details}){$RESET}\n\n";
         }

         foreach ($rounds as $roundIndex => $round) {
            $roundConnections = $round['connections'];
            $roundWorkers = $round['workers'];
            $roundClientWorkers = $round['client-workers'];

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
            $activeOpponent = $Opponent;
            echo "  {$BOLD}{$BLUE}▸ Starting {$Opponent->name}...{$RESET}\n";
            $this->startServer($Opponent, $roundWorkers);

            // @ Wait for server readiness
            if ( !$this->waitForServer() ) {
               echo "    {$RED}{$Opponent->name} failed to start!{$RESET}\n\n";
               $this->stopServer($Opponent);
               continue;
            }

            echo "    {$GREEN}{$Opponent->name} ready (port {$this->port}).{$RESET}\n";

            // @ Warmup
            echo "    {$DIM}Warming up ({$this->warmupDuration}s)...{$RESET}\n";
            $this->warmup();
            sleep(2);

            // @ Run loads
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

               if ($Load->group !== '' && $Load->group !== $prevGroup) {
                  echo "    {$BOLD}{$Load->group}{$RESET}\n";
                  $prevGroup = $Load->group;
               }

               $loadNum++;

               $Result = $this->command($Load, $roundConnections, $roundClientWorkers);

               $rps = $Result->rps !== null
                  ? "{$BOLD}{$GREEN}" . number_format((int) $Result->rps) . " {$this->metric}{$RESET}"
                  : "{$RED}N/A{$RESET}";
               $transfer = $Result->transfer !== null
                  ? "  {$DIM}({$Result->transfer}){$RESET}"
                  : '';
               $latency = $Result->latency !== null
                  ? "  {$DIM}({$Result->latency}){$RESET}"
                  : '';

               echo "    {$DIM}[{$loadNum}/{$totalLoads}]{$RESET} {$Load->label}...  {$rps}{$transfer}{$latency}\n";

               $label = $Load->label;
               if (
                  !isset($bestResults[$label])
                  || $Result->rps !== null && ($bestResults[$label]->rps === null || $Result->rps > $bestResults[$label]->rps)
               ) {
                  $bestResults[$label] = $Result;
               }
            }

            echo "\n";

            // @ Stop server
            $this->stopServer($Opponent);
            $activeOpponent = null;
         }

         $results[$Opponent->name] = $bestResults;
      }

      return $results;
   }

   // # Server lifecycle
   private function killPort (): void
   {
      exec("fuser -k {$this->port}/tcp > /dev/null 2>&1");
      exec("lsof -ti :{$this->port} 2>/dev/null | xargs kill -9 2>/dev/null");
      usleep(500_000);
   }
   private function startServer (Opponent $Opponent, int $workers): void
   {
      $script = $Opponent->script;
      putenv("BOOTGLY_WORKERS={$workers}");
      exec("BOOTGLY_WORKERS={$workers} php {$script} start > /dev/null 2>&1 &");
   }
   private function stopServer (Opponent $Opponent): void
   {
      $script = $Opponent->script;
      exec("php {$script} stop > /dev/null 2>&1");

      sleep(1);
      $this->killPort();
   }

   /**
    * Probe the WebSocket server by completing an upgrade handshake.
    *
    * A plain TCP connect is not enough — it only proves the listener is up, not
    * that the WS upgrade path works. We send a real RFC 6455 GET and require a
    * `101 Switching Protocols` status line before declaring the server ready.
    */
   private function waitForServer (): bool
   {
      $deadline = time() + $this->readyTimeout;
      $key = base64_encode(random_bytes(16));

      $request = "GET / HTTP/1.1\r\n"
         . "Host: 127.0.0.1:{$this->port}\r\n"
         . "Upgrade: websocket\r\n"
         . "Connection: Upgrade\r\n"
         . "Sec-WebSocket-Key: {$key}\r\n"
         . "Sec-WebSocket-Version: 13\r\n"
         . "\r\n";

      while (time() < $deadline) {
         $sock = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            timeout: 1,
            flags: STREAM_CLIENT_CONNECT
         );

         if ($sock === false) {
            usleep(250_000);
            continue;
         }

         @fwrite($sock, $request);
         stream_set_timeout($sock, 1);
         $response = (string) @fread($sock, 256);
         @fclose($sock);

         if (strpos($response, '101') !== false) {
            return true;
         }

         usleep(250_000);
      }

      return false;
   }

   // # Benchmark execution
   private function warmup (): void
   {
      $workerScript = __DIR__ . '/WS_Raw/worker.php';

      // @ Warm up in the active load's mode. A mismatched warmup is harmful — an
      //   echo client against a broadcast server amplifies (every connection
      //   resends each fan-out frame) into a server backlog that zeroes the
      //   following measurement.
      $FirstLoad = $this->loads[array_key_first($this->loads)] ?? null;
      $loadData = $FirstLoad !== null
         ? include $FirstLoad->file
         : ['mode' => 'echo', 'payload' => str_repeat('x', 32), 'binary' => false];

      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_warmup_');
      if ($tmpFile === false) return;

      file_put_contents($tmpFile, json_encode($loadData));

      exec(
         "php {$workerScript}"
         . " --host=127.0.0.1"
         . " --port={$this->port}"
         . " --connections={$this->warmupConnections}"
         . " --duration={$this->warmupDuration}"
         . " --load-file={$tmpFile}"
         . " --workers={$this->resolveClientWorkers()}"
         . " > /dev/null 2>&1"
      );

      @unlink($tmpFile);
   }

   private function command (Load $Load, int $connections = 0, int $clientWorkers = 0): Result
   {
      $workerScript = __DIR__ . '/WS_Raw/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      $loadData = include $Load->file;

      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_load_');
      if ($tmpFile === false) {
         return new Result();
      }

      file_put_contents($tmpFile, json_encode($loadData));

      $output = [];
      $cmd = "php {$workerScript}"
         . " --host=127.0.0.1"
         . " --port={$this->port}"
         . " --connections={$connections}"
         . " --duration={$this->duration}"
         . " --load-file={$tmpFile}"
         . " --workers={$clientWorkers}"
         . " 2>/dev/null";

      exec($cmd, $output);

      @unlink($tmpFile);

      return $this->parse(implode('', $output));
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
