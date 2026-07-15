<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — complete worker-proof sweep validation
 * --------------------------------------------------------------------------
 * Plans every selected opponent/load/round before a benchmark starts so a
 * late unschedulable matrix cannot leave a misleading partial sweep.
 * --------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Bootgly\Benchmarks\Runners;


use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Runner;


final class WorkerSweep
{
   /** @param array<array-key,array<string,mixed>> $rounds */
   public static function validate (
      Runner $Runner,
      Configs $Configs,
      array $rounds = [],
   ): void
   {
      if (!\in_array($Runner->name, ['tcp_client', 'http_client'], true)) {
         return;
      }

      $rounds = $rounds === [] ? [[]] : $rounds;
      $automaticWorkers = \max(1, (int) ((int) (\exec('nproc 2>/dev/null') ?: 1) / 2));
      $budget = $Runner->meta['worker-proof-budget'] ?? 'auto';
      $selectedOpponents = $Configs->opponents === null
         ? null
         : \array_map(Configs::slug(...), $Configs->opponents);
      $Include = static fn (string $file): mixed => include $file;
      $loads = [];

      foreach ($Runner->loads as $index => $Load) {
         if ($Configs->loads !== null && !\in_array($index + 1, $Configs->loads, true)) {
            continue;
         }

         $load = $Include($Load->file);
         if (!\is_array($load)) {
            throw new \RuntimeException("Invalid benchmark load data: {$Load->label}.");
         }
         $loads[$index] = $load;
      }

      foreach ($rounds as $round) {
         foreach ($Runner->opponents as $Opponent) {
            if (
               $selectedOpponents !== null
               && !\in_array(Configs::slug($Opponent->name), $selectedOpponents, true)
            ) {
               continue;
            }

            $roundWorkers = $round['server-workers'] ?? null;
            if (
               $roundWorkers !== null
               && !\is_int($roundWorkers)
               && !(
                  \is_string($roundWorkers)
                  && \preg_match('/\A[1-9]\d*\z/D', $roundWorkers) === 1
               )
            ) {
               throw new \InvalidArgumentException('Sweep server worker count must be positive.');
            }
            $workers = $roundWorkers === null
               ? ($Opponent->workers ?? $automaticWorkers)
               : (int) $roundWorkers;

            foreach ($loads as $index => $load) {
               $Load = $Runner->loads[$index];
               if (
                  $Load->opponents !== 'all'
                  && !\in_array($Opponent->name, \explode(',', $Load->opponents), true)
               ) {
                  continue;
               }

               WorkerWarmup::plan($load, $workers, $budget);
            }
         }
      }
   }
}
