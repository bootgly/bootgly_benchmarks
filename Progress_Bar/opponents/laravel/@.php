<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Progress_Bar — Laravel/Symfony Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$bootablesDir = dirname(__DIR__, 2) . '/bootables';

$Runner->add(new Opponent(
   name: 'Laravel',
   version: function () use ($bootablesDir) {
      $lock = @file_get_contents("{$bootablesDir}/laravel/composer.lock");
      if ($lock && preg_match('/"name":\s*"symfony\/console".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return $m[1];
      }
      return 'unknown';
   },
   script: __DIR__ . '/laravel.php',
));
