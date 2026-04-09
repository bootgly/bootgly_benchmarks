<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Code Runner
 * --------------------------------------------------------------------------
 * Local code execution runner. Spawns competitors as subprocesses,
 * measures time and memory via JSON result file.
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;
use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Result;
use Bootgly\ACI\Tests\Benchmark\Runner;


return new class extends Runner
{
   public protected(set) string $name = 'code';
   // * Config
   /**
    * Timeout in seconds for each competitor execution.
    */
   public int $timeout = 120;

   /**
    * Number of iterations per competitor. Best result is kept.
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
         '--iterations=N' => 'Number of iterations per competitor (default: 1)',
         '--timeout=N'    => 'Timeout in seconds per execution (default: 120)',
         '--warmup=N'     => 'Number of warmup runs (default: 0)',
      ];
   }

   public function run (Configs $Configs): array
   {
      $results = [];

      foreach ($this->competitors as $Competitor) {
         // ? Filter (case-insensitive)
         if ($Configs->competitors !== null && !in_array(strtolower($Competitor->name), array_map(strtolower(...), $Configs->competitors))) {
            continue;
         }

         // @ Identify competitor
         echo "\n  ▶ {$Competitor->name}\n\n";

         // @ Warmup
         for ($i = 0; $i < $this->warmup; $i++) {
            $this->execute($Competitor);
         }

         // @ Run iterations (keep best)
         $best = null;
         for ($i = 0; $i < $this->iterations; $i++) {
            $Result = $this->execute($Competitor);

            if ($Result === null) {
               continue;
            }

            if (
               $best === null
               || (
                  $Result->time !== null
                  && ($best->time === null || (float) $Result->time < (float) $best->time)
               )
            ) {
               $best = $Result;
            }
         }

         $results[$Competitor->name] = ['default' => $best ?? new Result];
      }

      return $results;
   }

   // @ Execution
   private function execute (Competitor $Competitor): null|Result
   {
      // @ Prepare result file (competitor writes JSON here instead of stdout)
      $resultFile = sys_get_temp_dir() . '/bootgly_bench_' . getmypid() . '_' . uniqid() . '.json';
      putenv("BENCHMARK_RESULT_FILE=$resultFile");

      // @ Inherit real terminal STDIO (allows visual rendering + stty/cursor)
      $descriptors = [
         0 => STDIN,
         1 => STDOUT,
         2 => STDERR,
      ];

      $process = proc_open(
         command: ['php', $Competitor->script],
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

      if ( !is_array($data) ) {
         return null;
      }

      return new Result(
         time: isset($data['time']) ? (string) abs((float) $data['time']) : null,
         memory: $data['memory'] ?? null,
      );
   }
};
