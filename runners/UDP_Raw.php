<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — UDP_Raw Runner
 * --------------------------------------------------------------------------
 * Raw UDP server benchmark runner using Bootgly UDP_Client_CLI as load generator.
 * Sends raw datagrams (defined by load) and counts echo responses.
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Data\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Loads;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;


return new class (
   port: 8080,
   connections: 514,
   duration: 10,
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'udp_raw';
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
      int $port = 8080,
      int $connections = 514,
      int $duration = 10,
   )
   {
      $this->port = $port;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'UDP server stopped after benchmark run.';
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
         '--connections=N'    => 'Number of UDP sockets (default: 514)',
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
         'Client'  => "Bootgly UDP_Client_CLI {$clientFlags}",
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
         $this->stopServer($Opponent);
         $activeOpponent = null;

         $results[$Opponent->name] = $loadResults;
      }

      return $results;
   }

   // # Server lifecycle
   private function killPort (): void
   {
      exec("fuser -k {$this->port}/udp > /dev/null 2>&1");
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
    * Probe the UDP server by sending an echo datagram and waiting for a reply.
    *
    * UDP has no handshake, so we can't use fsockopen like TCP.
    * Instead: open a "connected" UDP socket, send a tiny probe, and
    * wait for the echo response with a short stream_select timeout.
    */
   private function waitForServer (): bool
   {
      $deadline = time() + $this->readyTimeout;

      while (time() < $deadline) {
         $sock = @stream_socket_client(
            "udp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            timeout: 1,
            flags: STREAM_CLIENT_CONNECT
         );

         if ($sock === false) {
            usleep(250_000);
            continue;
         }

         @stream_socket_sendto($sock, "PING\n");

         // Wait up to 500 ms for an echo reply.
         $read = [$sock];
         $write = $except = [];
         $ready = @stream_select($read, $write, $except, 0, 500_000);

         if ($ready) {
            $reply = @stream_socket_recvfrom($sock, 65535);
            @fclose($sock);

            if ($reply !== false && $reply !== '') {
               return true;
            }
         }
         else {
            @fclose($sock);
         }

         usleep(250_000);
      }

      return false;
   }

   // # Benchmark execution
   private function warmup (): void
   {
      $workerScript = __DIR__ . '/UDP_Raw/worker.php';

      $tmpFile = tempnam(sys_get_temp_dir(), 'bench_warmup_');
      if ($tmpFile === false) return;

      file_put_contents($tmpFile, json_encode([
         'message' => "PING\n",
      ]));

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
      $workerScript = __DIR__ . '/UDP_Raw/worker.php';
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
