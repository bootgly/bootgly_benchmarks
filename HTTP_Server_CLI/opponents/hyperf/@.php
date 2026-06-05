<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Hyperf Competitor
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$bootablesDir = dirname(__DIR__, 2) . '/bootables';

$Runner->add(new Competitor(
   name: 'Hyperf',
   version: function () use ($bootablesDir) {
      $lock = @file_get_contents("{$bootablesDir}/hyperf/composer.lock");
      if ($lock && preg_match('/"name":\s*"hyperf\/framework".*?"version":\s*"([^"]+)"/s', $lock, $m)) {
         return 'v' . ltrim($m[1], 'v');
      }
      return 'unknown';
   },
   script: __DIR__ . '/hyperf.php',
));
