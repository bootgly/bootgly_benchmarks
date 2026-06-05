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
      if (isset($options['pipeline'])) {
         $this->pipeline = (int) $options['pipeline'];
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

      // @ Surface active runner config for the `.marks` Config header.
      //   TestCommand merges this into the metadata block written by Summary::save.
      $this->meta['connections']    = $this->connections;
      $this->meta['duration']       = $this->duration;
      $this->meta['client-workers'] = $this->workers;
      $this->meta['pipeline']       = $this->pipeline;
   }
   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of TCP connections (default: 514)',
         '--duration=N'       => 'Benchmark duration in seconds (default: 10)',
         '--pipeline=N'       => 'HTTP pipelining factor (default: 1)',
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

      $clientFlags = "-c{$connectionsDisplay} -d{$this->duration}s";
      $clientFlags .= " -w{$clientWorkersDisplay}";
      if ($this->pipeline > 1) $clientFlags .= " -p{$this->pipeline}";

      $sections['Configuration'] = [
         'Client'  => "Bootgly TCP_Client_CLI {$clientFlags}",
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
         // ? Filter (slug-normalized, e.g. "Swoole (Base)" matches "swoole-base")
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
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
            echo "  {$BOLD}{$BLUE}▸ {$Opponent->name}: {$roundCount} rounds ({$details}){$RESET}\n\n";
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
            // @ Let server recover from warmup connection cleanup
            sleep(2);

            // @ Run loads
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

               $Result = $this->command($Load, $roundConnections, $roundClientWorkers);

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

               // @ Keep best RPS per load
               $label = $Load->label;
               if (
                  !isset($bestResults[$label])
                  || ($Result->rps !== null && ($bestResults[$label]->rps === null || $Result->rps > $bestResults[$label]->rps))
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
      $baseWorkers = $this->opponents[0]->workers ?? max(1, (int) ($nproc / 2));
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
      $workerScript = __DIR__ . '/TCP_Client/worker.php';

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
      $workerScript = __DIR__ . '/TCP_Client/worker.php';
      $connections = $connections > 0 ? $connections : $this->connections;
      $clientWorkers = $clientWorkers > 0 ? $clientWorkers : $this->resolveClientWorkers();

      // @ Load load data from PHP file
      $loadData = include $Load->file;

      if (is_array($loadData) === false) {
         echo "      Preflight failed: invalid load data.\n";

         return new Result();
      }

      $preflight = $this->preflight($loadData);

      if ($preflight !== '') {
         echo "      Preflight failed: {$preflight}\n";

         return new Result();
      }

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
      if ($this->pipeline > 1) $cmd .= " --pipeline={$this->pipeline}";
      $cmd .= " 2>/dev/null";

      exec($cmd, $output);

      @unlink($tmpFile);

      // @ Parse JSON output
      $json = implode('', $output);

      return $this->parse($json);
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
