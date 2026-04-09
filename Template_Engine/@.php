<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Template_Engine (foreach directive)
 * --------------------------------------------------------------------------
 *
 * Compares foreach directive performance with 1,000,000 items.
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


$Runner = include __DIR__ . '/../runners/Code.php';
$Runner->iterations = 1;

$artifactDir = __DIR__ . '/artifacts';

// @ Competitors
$Runner->add(new Competitor(
   name: 'Bootgly',
   version: fn () => 'v' . BOOTGLY_VERSION,
   script: __DIR__ . '/competitors/bootgly.php',
));
$Runner->add(new Competitor(
   name: 'Laravel',
   version: function () use ($artifactDir) {
      $lock = @file_get_contents("{$artifactDir}/laravel/composer.lock");
      if ($lock && preg_match('/"name":\s*"illuminate\/view".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return $m[1];
      }
      return 'unknown';
   },
   script: __DIR__ . '/competitors/laravel.php',
));

return $Runner;
