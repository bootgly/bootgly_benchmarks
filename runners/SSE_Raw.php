<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — SSE_Raw Runner
 * --------------------------------------------------------------------------
 * Server-Sent Events benchmark runner. Opens N persistent `text/event-stream`
 * connections and counts the events the server pushes, or how fast it can
 * establish streams.
 *
 * Why this is not the `tcp_client` runner. That runner measures a closed loop:
 * it sends a request, frames a complete response, and divides by time. SSE has
 * neither half of that. The client writes once and never again, so nothing
 * paces the server; and an event stream only "completes" when it closes, so a
 * request/response counter would report one response per stream teardown —
 * connection churn, not event throughput.
 *
 * The number here is therefore what the SERVER chose to deliver. With the
 * benchmark router the offered rate is `connections × BENCHMARK_SSE_EVENTS`,
 * so a run that lands under it is a real transport finding rather than a slow
 * client.
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Code\__String\Escapeable\Text\Formattable;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Configs\Load;
use Bootgly\ACI\Tests\Benchmark\Configs\Loads;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\Benchmarks\Runners\RunArtifacts;
use Bootgly\Benchmarks\Runners\RunProcess;

require_once __DIR__ . '/RunArtifacts.php';


return new class (
   port: 8082,
   connections: 514,
   duration: 10,
) extends Runner
{
   use Formattable;

   public protected(set) string $name = 'sse_raw';
   public string $metric = 'events/s';

   // * Config
   public int $port;
   public int $connections;
   public int $duration;
   public int $workers = 0;
   // # Events per tick, per stream — mirrored into the server environment.
   public int $events = 10;
   // # warmup
   public int $warmupDuration = 2;
   public int $warmupConnections = 32;
   // # server readiness
   public int $readyTimeout = 10;

   private null|RunArtifacts $ServerArtifacts = null;
   private null|RunProcess $ServerProcess = null;


   public function __construct (
      int $port = 8082,
      int $connections = 514,
      int $duration = 10,
   )
   {
      $this->port = $port;
      $this->connections = $connections;
      $this->duration = $duration;
      $this->postMessage = 'SSE server stopped after benchmark run.';
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
      if (isset($options['events'])) {
         $this->events = max(1, (int) $options['events']);
      }

      $this->workers = $this->resolve();

      // @ Surface the active config into the `.marks` Config header so a sweep
      //   has an X axis. `events` belongs here too: it selects the offered
      //   rate, so two runs with different values are not comparable and must
      //   not collapse into one Config.
      $this->meta['connections']    = $this->connections;
      $this->meta['duration']       = $this->duration;
      $this->meta['client-workers'] = $this->workers;
      $this->meta['sse-events']     = $this->events;
   }

   public function options (): array
   {
      return [
         '--client-workers=N' => 'Number of client workers (default: auto)',
         '--connections=N'    => 'Number of event-stream connections (default: 514)',
         '--duration=N'       => 'Benchmark duration in seconds (default: 10)',
         '--events=N'         => 'Events per second, per stream (default: 10)',
      ];
   }

   public function load (string $loadsDir): void
   {
      $this->loads = Loads::loadPhp($loadsDir);
   }

   /**
    * @return array<string,array<string,string>>
    */
   public function banner (Configs $Configs): array
   {
      $offered = $this->connections * $this->events;

      return [
         'Client' => [
            'Engine'         => 'Bootgly SSE_Raw (raw sockets)',
            'Client workers' => (string) $this->resolve(),
            'Connections'    => (string) $this->connections,
            'Duration'       => "{$this->duration} s",
            'Warmup'         => "{$this->warmupConnections} streams · {$this->warmupDuration} s",
         ],
         'Server' => [
            'Port'           => (string) $this->port,
            'Events / tick'  => (string) $this->events,
            'Offered rate'   => number_format($offered) . ' events/s',
         ],
      ];
   }

   // # Workers
   private function resolve (): int
   {
      if ($this->workers > 0) {
         return $this->workers;
      }

      $nproc = (int) (exec('nproc 2>/dev/null') ?: 1);
      $cpuWorkers = max(1, (int) ($nproc / 2));
      $fdWorkers = (int) max(1, ceil($this->connections / 1000));

      return max($cpuWorkers, $fdWorkers);
   }

   public function run (Configs $Configs): array
   {
      $BLUE  = self::wrap(self::_BLUE_FOREGROUND);
      $GREEN = self::wrap(self::_GREEN_FOREGROUND);
      $RED   = self::wrap(self::_RED_FOREGROUND);
      $BOLD  = self::wrap(self::_BOLD_STYLE);
      $DIM   = self::wrap(self::_DIM_STYLE);
      $RESET = self::_RESET_FORMAT;

      $results = [];

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
            $this->stop($Opponent);
         }
         exit(130);
      });

      $filtered = [];
      foreach ($this->loads as $index => $Load) {
         if ($Configs->loads !== null && ! in_array($index + 1, $Configs->loads)) {
            continue;
         }
         $filtered[$index] = $Load;
      }
      $totalLoads = count($filtered);

      foreach ($this->opponents as $Opponent) {
         if (
            $Configs->opponents !== null
            && ! in_array(
               Configs::slug($Opponent->name),
               array_map(Configs::slug(...), $Configs->opponents),
            )
         ) {
            continue;
         }

         putenv('BENCHMARK_PROFILE_SCOPE=' . Configs::slug($Opponent->name));

         $workers = $Opponent->workers
            ?? max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2));

         $this->clear();

         $activeOpponent = $Opponent;
         $stopping = false;

         try {
            echo "  {$BOLD}{$BLUE}▸ Starting {$Opponent->name}...{$RESET}\n";
            $this->start($Opponent, $workers);

            if ($this->wait() === false) {
               echo "    {$RED}{$Opponent->name} failed to start!{$RESET}\n\n";
               $stopping = true;
               $this->stop($Opponent);
               $activeOpponent = null;
               $stopping = false;

               if ($interrupted) {
                  exit(130);
               }

               continue;
            }

            echo "    {$GREEN}{$Opponent->name} ready (port {$this->port}).{$RESET}\n";

            echo "    {$DIM}Warming up ({$this->warmupDuration}s)...{$RESET}\n";
            $this->warm();
            sleep(1);

            $loadResults = [];
            $loadNum = 0;
            $prevGroup = '';

            foreach ($this->loads as $index => $Load) {
               if ($Configs->loads !== null && ! in_array($index + 1, $Configs->loads)) {
                  continue;
               }

               if (
                  $Load->opponents !== 'all'
                  && ! in_array($Opponent->name, explode(',', $Load->opponents))
               ) {
                  continue;
               }

               if ($Load->group !== '' && $Load->group !== $prevGroup) {
                  echo "    {$BOLD}{$Load->group}{$RESET}\n";
                  $prevGroup = $Load->group;
               }

               $loadNum++;

               $Result = $this->command($Load);

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

            $stopping = true;
            $this->stop($Opponent);
            $activeOpponent = null;
            $stopping = false;

            if ($interrupted) {
               exit(130);
            }

            $results[$Opponent->name] = $loadResults;
         }
         catch (Throwable $Throwable) {
            if ($stopping === false && $activeOpponent !== null) {
               $Cleanup = $activeOpponent;
               $activeOpponent = null;
               $stopping = true;

               try {
                  $this->stop($Cleanup);
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
   private function clear (): void
   {
      exec("fuser -k {$this->port}/tcp > /dev/null 2>&1");
      exec("lsof -ti :{$this->port} 2>/dev/null | xargs kill -9 2>/dev/null");
      usleep(500_000);
   }

   private function start (Opponent $Opponent, int $workers): void
   {
      putenv("BOOTGLY_WORKERS={$workers}");
      $this->ServerArtifacts?->clean();
      $this->ServerArtifacts = RunArtifacts::create('sse-raw-server');
      $this->ServerProcess = $this->ServerArtifacts->start(
         [PHP_BINARY, $Opponent->script, 'start'],
         [
            'BENCHMARK_SERVER_DIR' => $this->ServerArtifacts->directory,
            'BOOTGLY_WORKERS' => (string) $workers,
            // ! The offered rate is a SERVER-side knob: the client cannot ask
            //   for events. Passing it here is what makes `--events` mean
            //   anything at all.
            'BENCHMARK_SSE_EVENTS' => (string) $this->events,
         ],
      );
   }

   private function stop (Opponent $Opponent): void
   {
      $Artifacts = RunArtifacts::create('sse-raw-server-stop');

      try {
         $Artifacts->start([PHP_BINARY, $Opponent->script, 'stop'])->wait(10.0, 2.0);
      }
      finally {
         $Artifacts->clean();
         sleep(1);
         $this->clear();

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

   /**
    * Probe readiness by OPENING an event stream, not by connecting.
    *
    * A TCP connect proves the listener is up; it does not prove the SSE path
    * works. A refused stream answers an ordinary finite response, so requiring
    * the `text/event-stream` head is what separates "ready" from "answering".
    */
   private function wait (): bool
   {
      $deadline = time() + $this->readyTimeout;

      while (time() < $deadline) {
         $Socket = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $code,
            $error,
            timeout: 1,
         );

         if ($Socket === false) {
            usleep(250_000);
            continue;
         }

         @fwrite(
            $Socket,
            "GET /sse/stream HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n\r\n",
         );
         stream_set_timeout($Socket, 1);
         $response = (string) @fread($Socket, 512);
         @fclose($Socket);

         if (str_contains($response, 'text/event-stream')) {
            return true;
         }

         usleep(250_000);
      }

      if ($this->ServerProcess !== null && $this->ServerProcess->check() === false) {
         $this->ServerProcess->wait();
      }

      return false;
   }

   // # Benchmark execution
   private function warm (): void
   {
      $First = $this->loads[array_key_first($this->loads)] ?? null;
      $data = $First !== null
         ? include $First->file
         : ['mode' => 'stream', 'path' => '/sse/stream'];

      $Artifacts = RunArtifacts::create('sse-raw-warmup');
      $input = $Artifacts->write('input.json', json_encode($data, JSON_THROW_ON_ERROR));

      try {
         $Artifacts->run([
            PHP_BINARY,
            __DIR__ . '/SSE_Raw/worker.php',
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$this->warmupConnections}",
            "--duration={$this->warmupDuration}",
            "--load-file={$input}",
            '--workers=1',
         ], $this->warmupDuration + 30.0, 2.0);
      }
      finally {
         $Artifacts->clean();
      }
   }

   private function command (Load $Load): Result
   {
      $data = include $Load->file;

      $Artifacts = RunArtifacts::create('sse-raw-load');
      $input = $Artifacts->write('input.json', json_encode($data, JSON_THROW_ON_ERROR));

      try {
         $execution = $Artifacts->run([
            PHP_BINARY,
            __DIR__ . '/SSE_Raw/worker.php',
            '--host=127.0.0.1',
            "--port={$this->port}",
            "--connections={$this->connections}",
            "--duration={$this->duration}",
            "--load-file={$input}",
            '--workers=' . $this->resolve(),
         ], $this->duration + 60.0, 2.0);

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

      if (is_array($data) === false) {
         return new Result();
      }

      return new Result(
         rps: isset($data['rps']) ? (float) $data['rps'] : null,
         latency: isset($data['latency']) ? (string) $data['latency'] : null,
         transfer: isset($data['transfer']) ? (string) $data['transfer'] : null,
      );
   }
};
