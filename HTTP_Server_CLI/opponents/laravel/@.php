<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Laravel Opponents
 * --------------------------------------------------------------------------
 *
 * The same Laravel app (bootables/laravel) fronted by different web servers.
 * The nginx/Apache variants run per-request (PHP-FPM, no persistent worker);
 * the Octane variant runs persistent in-memory workers on Swoole.
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
   name: 'Laravel (nginx)',
   version: $laravelVersion,
   script: __DIR__ . '/laravel-nginx.php',
));

$Runner->add(new Opponent(
   name: 'Laravel (Apache)',
   version: $laravelVersion,
   script: __DIR__ . '/laravel-apache.php',
));

$Runner->add(new Opponent(
   name: 'Laravel (Octane)',
   version: $laravelVersion,
   script: __DIR__ . '/laravel-octane.php',
));
