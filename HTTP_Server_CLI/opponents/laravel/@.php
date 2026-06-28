<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel Opponents
 * --------------------------------------------------------------------------
 *
 * The Laravel app (bootables/laravel) served by Octane on Swoole — persistent
 * in-memory workers, the fast Laravel stack. Runs in-process inside the
 * self-contained bench image (bootgly/bootgly_benchmarks:laravel-octane).
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$laravelVersion = function (): string {
   $lock = __DIR__ . '/../../bootables/laravel/composer.lock';

   if (is_file($lock) === true) {
      $data = json_decode((string) file_get_contents($lock), true);

      foreach (($data['packages'] ?? []) as $Package) {
         if (($Package['name'] ?? '') === 'laravel/framework') {
            return $Package['version'] ?? 'unknown';
         }
      }
   }

   return 'unknown';
};

$Runner->add(new Opponent(
   name: 'Laravel (Octane)',
   version: $laravelVersion,
   script: __DIR__ . '/laravel-octane.php',
));
