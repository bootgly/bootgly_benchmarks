<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Express (Node.js) Opponent
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Opponent;


/** @var \Bootgly\ACI\Tests\Benchmark\Runner $Runner */

$Runner->add(new Opponent(
   name: 'Express',
   version: function () {
      $node = exec('node -v 2>/dev/null') ?: 'unknown';

      $express = 'unknown';
      $manifest = dirname(__DIR__, 2) . '/bootables/express/node_modules/express/package.json';
      if (is_file($manifest)) {
         $package = json_decode((string) file_get_contents($manifest), true);
         $express = is_array($package) && is_string($package['version'] ?? null)
            ? "v{$package['version']}"
            : 'unknown';
      }

      return "{$express} (node {$node})";
   },
   script: __DIR__ . '/express.php',
));
