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
      if (isset($options['iterations'])) {
         $this->iterations = (int) $options['iterations'];
      }
      if (isset($options['timeout'])) {
         $this->timeout = (int) $options['timeout'];
      }
      if (isset($options['warmup'])) {
         $this->warmup = (int) $options['warmup'];
      }
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
      $results = [];

      foreach ($this->opponents as $Opponent) {
         // ? Filter (slug-normalized, e.g. "Laravel Octane" matches "laravel-octane")
         if ($Configs->opponents !== null && !in_array(Configs::slug($Opponent->name), array_map(Configs::slug(...), $Configs->opponents))) {
            continue;
         }

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

      return $results;
   }

   // @ Execution
   /**
    * @return null|array<string,Result> Operation label => Result (flat results map to ['default' => Result]).
    */
   private function execute (Opponent $Opponent): null|array
   {
      // @ Prepare result file (opponent writes JSON here instead of stdout)
      $resultFile = sys_get_temp_dir() . '/bootgly_bench_' . getmypid() . '_' . uniqid() . '.json';
      putenv("BENCHMARK_RESULT_FILE=$resultFile");

      // @ Inherit real terminal STDIO (allows visual rendering + stty/cursor)
      $descriptors = [
         0 => STDIN,
         1 => STDOUT,
         2 => STDERR,
      ];

      $process = proc_open(
         command: ['php', $Opponent->script],
         descriptor_spec: $descriptors,
         pipes: $pipes,
      );

      if ($process === false) {
         putenv('BENCHMARK_RESULT_FILE');
         return null;
      }

      $exitCode = proc_close($process);

      putenv('BENCHMARK_RESULT_FILE');

      if ($exitCode !== 0) {
         @unlink($resultFile);
         return null;
      }

      // @ Read result from file
      if ( !file_exists($resultFile) ) {
         return null;
      }

      $json = file_get_contents($resultFile);
      @unlink($resultFile);

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
};
