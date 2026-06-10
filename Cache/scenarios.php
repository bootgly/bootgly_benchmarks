<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — Shared workload
 * --------------------------------------------------------------------------
 *
 * The single canonical cache workload, run identically by every driver.
 * Each operation is wrapped in Benchmark::start()/stop(); dataset prep stays
 * outside the timed regions. Returns a closure invoked with a configured
 * Cache instance:  (require 'scenarios.php')($Cache);
 */

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Benchmark;


return function (Cache $Cache): void
{
   // ! Workload size
   $N = 2000;                            // per-key operations
   $M = 500;                             // tagged group size
   $large = array_fill(0, 50, 'xyz');    // non-scalar payload (exercises serialize)

   // ! Key set
   $keys = [];
   for ($i = 0; $i < $N; $i++) {
      $keys[$i] = "k:$i";
   }

   // @ store — N small scalars
   Benchmark::start('store');
   for ($i = 0; $i < $N; $i++) {
      $Cache->store($keys[$i], $i);
   }
   Benchmark::stop('store');

   // @ store:large — N serialized arrays
   Benchmark::start('store:large');
   for ($i = 0; $i < $N; $i++) {
      $Cache->store("big:$i", $large);
   }
   Benchmark::stop('store:large');

   // @ store:tagged — M keys with 2 tags each (pipelined SET+SADD on Redis)
   Benchmark::start('store:tagged');
   for ($i = 0; $i < $M; $i++) {
      $Cache->store("tg:$i", $i, 0, ['t1', 't2']);
   }
   Benchmark::stop('store:tagged');

   // @ fetch:hit — read N existing keys
   Benchmark::start('fetch:hit');
   for ($i = 0; $i < $N; $i++) {
      $Cache->fetch($keys[$i]);
   }
   Benchmark::stop('fetch:hit');

   // @ fetch:miss — read N absent keys
   Benchmark::start('fetch:miss');
   for ($i = 0; $i < $N; $i++) {
      $Cache->fetch("absent:$i");
   }
   Benchmark::stop('fetch:miss');

   // @ check — N existence checks
   Benchmark::start('check');
   for ($i = 0; $i < $N; $i++) {
      $Cache->check($keys[$i]);
   }
   Benchmark::stop('check');

   // @ increment — N atomic increments on one hot key (rate-limiter primitive)
   Benchmark::start('increment');
   for ($i = 0; $i < $N; $i++) {
      $Cache->increment('counter');
   }
   Benchmark::stop('increment');

   // @ tags:invalidate — store M tagged keys, then drop the whole tag
   for ($i = 0; $i < $M; $i++) {
      $Cache->store("tagged:$i", $i, 0, ['group']);
   }
   Benchmark::start('tags:invalidate');
   $Cache->invalidate('group');
   Benchmark::stop('tags:invalidate');

   // @ resolve:miss — get-or-compute, all misses (compute + store)
   Benchmark::start('resolve:miss');
   for ($i = 0; $i < $N; $i++) {
      $Cache->resolve("r:$i", 0, static fn () => $i);
   }
   Benchmark::stop('resolve:miss');

   // @ resolve:hit — get-or-compute, all hits
   Benchmark::start('resolve:hit');
   for ($i = 0; $i < $N; $i++) {
      $Cache->resolve("r:$i", 0, static fn () => $i);
   }
   Benchmark::stop('resolve:hit');

   // @ mixed — 80% fetch / 20% store
   Benchmark::start('mixed');
   for ($i = 0; $i < $N; $i++) {
      if ($i % 5 === 0) {
         $Cache->store($keys[$i], $i);
      }
      else {
         $Cache->fetch($keys[$i]);
      }
   }
   Benchmark::stop('mixed');

   // @ delete — delete N keys
   Benchmark::start('delete');
   for ($i = 0; $i < $N; $i++) {
      $Cache->delete($keys[$i]);
   }
   Benchmark::stop('delete');
};
