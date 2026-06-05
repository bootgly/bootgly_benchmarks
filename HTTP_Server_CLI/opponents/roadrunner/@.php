<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — RoadRunner Competitor
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Competitor;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$bootablesDir = dirname(__DIR__, 2) . '/bootables';

$Runner->add(new Competitor(
   name: 'RoadRunner',
   version: function () use ($bootablesDir) {
      $output = exec("cd {$bootablesDir}/roadrunner && ./rr -v 2>/dev/null") ?: '';
      preg_match('/version ([0-9]+\.[0-9]+\.[0-9]+)/', $output, $m);
      return isset($m[1]) ? 'v' . $m[1] : 'unknown';
   },
   script: __DIR__ . '/roadrunner.php',
));
