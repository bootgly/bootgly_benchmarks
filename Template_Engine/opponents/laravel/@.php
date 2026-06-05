<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Template_Engine — Laravel/Blade Competitor
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$bootablesDir = dirname(__DIR__, 2) . '/bootables';

$Runner->add(new Competitor(
   name: 'Laravel',
   version: function () use ($bootablesDir) {
      $lock = @file_get_contents("{$bootablesDir}/laravel/composer.lock");
      if ($lock && preg_match('/"name":\s*"illuminate\/view".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return $m[1];
      }
      return 'unknown';
   },
   script: __DIR__ . '/laravel.php',
));
