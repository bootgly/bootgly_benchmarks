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

      // ! Materialise the auto client-worker count so $this->meta captures the
      //   resolved value (nproc / 2) instead of the `0` auto sentinel.
      $this->workers = $this->resolveClientWorkers();

      // @ Surface the active runner config into the `.marks` Config header so
      //   chart.py can use connections (or workers) as a sweep axis — without
      //   these keys every run records the same Config and the X axis collapses.
      $this->meta['connections']    = $this->connections;
      $this->meta['duration']       = $this->duration;
      $this->meta['client-workers'] = $this->workers;
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
      $workersDisplay = (string) ($this->opponents[0]->workers ?? $defaultWorkers);
      $clientWorkersDisplay = (string) $this->resolveClientWorkers();

      $clientFlags = "-c{$this->connections} -d{$this->duration}s -w{$clientWorkersDisplay}";

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

   public function run (Configs $Configs): array
   {
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
         // ? Filter
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

            if ($Load->group !== '' && $Load->group !== $prevGroup) {
               echo "    {$BOLD}{$Load->group}{$RESET}\n";
               $prevGroup = $Load->group;
            }

            $loadNum++;

            $Result = $this->command($Load, $this->connections, $this->workers);

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
