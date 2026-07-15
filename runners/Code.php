<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Code Runner
 * --------------------------------------------------------------------------
 * Local code execution runner. Spawns opponents as subprocesses,
 * measures time and memory via JSON result file.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;
use Bootgly\Benchmarks\Runners\RunArtifacts;

require_once __DIR__ . '/RunArtifacts.php';


return new class extends Runner
{
   public protected(set) string $name = 'code';
   // * Config
   /**
    * Timeout in seconds for each opponent execution.
    */
   public int $timeout = 120;

   /**
    * Number of iterations per opponent. Best result is kept.
    */
   public int $iterations = 1;

   /**
    * Number of warmup executions (results discarded).
    */
   public int $warmup = 0;


   public function configure (array $options): void
   {
      $Parse = static function (mixed $value, string $name, int $minimum): int {
         if (is_bool($value)) {
            throw new InvalidArgumentException(
               "Code benchmark {$name} requires an explicit integer value."
            );
         }
         $parsed = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum]],
         );
         if ($parsed === false) {
            throw new InvalidArgumentException(
               "Code benchmark {$name} must be an integer greater than or equal to {$minimum}."
            );
         }

         return $parsed;
      };

      if (isset($options['iterations'])) {
         $this->iterations = $Parse($options['iterations'], 'iterations', 1);
      }
      if (isset($options['timeout'])) {
         $this->timeout = $Parse($options['timeout'], 'timeout', 1);
      }
      if (isset($options['warmup'])) {
         $this->warmup = $Parse($options['warmup'], 'warmup', 0);
      }

      $this->meta['iterations'] = $this->iterations;
      $this->meta['timeout'] = $this->timeout;
      $this->meta['warmup'] = $this->warmup;
   }
   public function options (): array
   {
      return [
         '--iterations=N' => 'Number of iterations per opponent (default: 1)',
         '--timeout=N'    => 'Timeout in seconds per execution (default: 120)',
         '--warmup=N'     => 'Number of warmup runs (default: 0)',
      ];
   }

   public function run (Configs $Configs): array
   {
      // ! Visual Code benchmarks intentionally inherit caller stdio in text
      //   mode. JSON mode isolates child channels in regular files so stdout
      //   remains one document. Record the different execution environment so
      //   marks from the two modes are not mistaken for equivalent samples.
      $this->meta['execution-stdio'] = $Configs->format === 'json'
         ? 'isolated-files+closed-stdin'
         : 'inherited-descriptors+isolated-process-group';

      $results = [];

      foreach ($this->opponents as $Opponent) {
         // ? Filter (slug-normalized, e.g. "Laravel Octane" matches "laravel-octane")
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

         putenv('BENCHMARK_PROFILE_SCOPE=' . Configs::slug($Opponent->name));

         // @ Identify opponent
         echo "\n  ▶ {$Opponent->name}\n\n";

         // @ Warmup
         for ($i = 0; $i < $this->warmup; $i++) {
            $this->execute($Opponent);
         }

         // @ Run iterations (keep best per label)
         $bestByLabel = [];
         for ($i = 0; $i < $this->iterations; $i++) {
            $Results = $this->execute($Opponent);

            if ($Results === null) {
               continue;
            }

            foreach ($Results as $label => $Result) {
               $current = $bestByLabel[$label] ?? null;

               if (
                  $current === null
                  || (
                     $Result->time !== null
                     && ($current->time === null || (float) $Result->time < (float) $current->time)
                  )
               ) {
                  $bestByLabel[$label] = $Result;
               }
            }
         }

         // ! Fully unavailable opponent → empty map; the report renders N/A per operation
         $results[$Opponent->name] = $bestByLabel;
      }

      putenv('BENCHMARK_PROFILE_SCOPE');

      return $results;
   }

   // @ Execution
   /**
    * @return null|array<string,Result> Operation label => Result (flat results map to ['default' => Result]).
    */
   private function execute (Opponent $Opponent): null|array
   {
      // @ Prepare result file (opponent writes JSON here instead of stdout)
      $resultRelative = 'results/code/'
         . Configs::slug($Opponent->name) . '-'
         . bin2hex(random_bytes(12)) . '.json';
      $resultFile = $this->Artifacts !== null
         ? $this->Artifacts->resolve($resultRelative)
         : sys_get_temp_dir() . '/bootgly_bench_' . getmypid() . '_' . bin2hex(random_bytes(12)) . '.json';
      putenv("BENCHMARK_RESULT_FILE=$resultFile");

      if (getenv('BENCHMARK_FORMAT') === 'json' && $this->Artifacts !== null) {
         // # Machine mode keeps every child byte outside the harness channels.
         $child = $this->spawn(
            [PHP_BINARY, $Opponent->script],
            'code-' . Configs::slug($Opponent->name),
            timeout: (float) $this->timeout,
         );
         $exitCode = $child['exit'];
      }
      else {
         // @ Text mode preserves caller descriptors because visual benchmarks
         //   may intentionally exercise cursor/stty rendering behavior.
         $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
         ];
         $groupReady = $resultFile . '.group-ready';
         $environment = getenv();
         $environment = is_array($environment) ? $environment : [];
         $environment['BOOTGLY_PROCESS_GROUP_READY'] = $groupReady;

         $process = proc_open(
            command: RunArtifacts::isolate([PHP_BINARY, $Opponent->script]),
            descriptor_spec: $descriptors,
            pipes: $pipes,
            env_vars: $environment,
            options: ['bypass_shell' => true],
         );

         if ($process === false) {
            putenv('BENCHMARK_RESULT_FILE');
            return null;
         }

         $status = proc_get_status($process);
         $PID = is_array($status) && isset($status['pid']) ? (int) $status['pid'] : null;
         $groupDeadline = microtime(true) + 2.0;
         $groupStarted = false;

         do {
            if (
               $PID !== null
               && is_file($groupReady)
               && trim((string) @file_get_contents($groupReady)) === (string) $PID
            ) {
               $groupStarted = true;
               break;
            }

            $status = proc_get_status($process);
            if (!is_array($status) || !$status['running']) {
               break;
            }

            usleep(1_000);
         } while (microtime(true) < $groupDeadline);

         @unlink($groupReady . '.tmp');
         @unlink($groupReady);

         if (!$groupStarted || $PID === null) {
            @proc_terminate($process, 9);
            proc_close($process);
            putenv('BENCHMARK_RESULT_FILE');

            return null;
         }

         $exitCode = $this->wait($process, $PID);
      }

      putenv('BENCHMARK_RESULT_FILE');

      if ($exitCode !== 0) {
         if ($this->Artifacts === null) {
            @unlink($resultFile);
         }
         return null;
      }

      // @ Read result from file
      if ( !file_exists($resultFile) ) {
         return null;
      }

      $json = file_get_contents($resultFile);
      if ($this->Artifacts === null) {
         @unlink($resultFile);
      }

      $data = json_decode($json, true);

      if ( !is_array($data) || $data === [] ) {
         return null;
      }

      // ? Detect label map (every value is an array) vs flat single result
      $isMap = true;
      foreach ($data as $entry) {
         if ( !is_array($entry) ) {
            $isMap = false;
            break;
         }
      }

      // ! Normalize flat result to a single 'default' label
      if ($isMap === false) {
         $data = ['default' => $data];
      }

      // @ Build one Result per label
      $Results = [];
      foreach ($data as $label => $entry) {
         $Results[(string) $label] = new Result(
            time: isset($entry['time']) ? (string) abs((float) $entry['time']) : null,
            memory: $entry['memory'] ?? null,
         );
      }

      return $Results;
   }

   /**
    * Enforce the advertised timeout while preserving inherited text stdio.
    *
    * @param resource $Process
    */
   private function wait (mixed $Process, int $PGID): int
   {
      $deadline = microtime(true) + $this->timeout;
      $observedExit = null;
      $signal = null;
      $timedOut = false;

      $Poll = static function () use (&$Process, &$observedExit, &$signal, $PGID): bool {
         if (is_resource($Process)) {
            $status = proc_get_status($Process);
            if (is_array($status) && !$status['running']) {
               $observedExit = ($status['exitcode'] ?? -1) >= 0
                  ? (int) $status['exitcode']
                  : $observedExit;
               $signal = ($status['signaled'] ?? false) && isset($status['termsig'])
                  ? (int) $status['termsig']
                  : $signal;
               $closedExit = proc_close($Process);
               $Process = null;
               $observedExit ??= $closedExit >= 0 ? $closedExit : null;
            }
            else if (is_array($status) && $status['running']) {
               return true;
            }
         }

         return @posix_kill(-$PGID, 0);
      };
      $Signal = static function (int $number) use (&$Process, $PGID): void {
         if (@posix_kill(-$PGID, $number)) {
            return;
         }
         if (is_resource($Process)) {
            @proc_terminate($Process, $number);
         }
      };

      while ($Poll()) {
         if (microtime(true) >= $deadline) {
            $timedOut = true;
            $Signal(15);
            $termDeadline = microtime(true) + 2.0;
            do {
               usleep(10_000);
               $running = $Poll();
            } while ($running && microtime(true) < $termDeadline);

            if ($running) {
               $Signal(9);
               $killDeadline = microtime(true) + 2.0;
               do {
                  usleep(10_000);
                  $running = $Poll();
               } while ($running && microtime(true) < $killDeadline);
            }

            if ($running) {
               throw new RuntimeException(
                  "Code benchmark process group {$PGID} survived TERM/KILL escalation"
               );
            }

            break;
         }

         usleep(10_000);
      }

      return $timedOut
         ? 124
         : ($observedExit ?? ($signal !== null ? 128 + $signal : 255));
   }
};
