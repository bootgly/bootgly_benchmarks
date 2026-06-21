<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — File driver
 * --------------------------------------------------------------------------
 *
 * Runs the canonical cache workload against the File driver (always available).
 * Result is written to BENCHMARK_RESULT_FILE (set by the Runner).
 */

$bootglyDir = __DIR__ . '/../../../../bootgly';

// @ Minimal autoload (no platform boot)
define('BOOTGLY_ROOT_BASE', $bootglyDir);
define('BOOTGLY_ROOT_DIR', $bootglyDir . DIRECTORY_SEPARATOR);
define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
define('BOOTGLY_STORAGE_BASE', BOOTGLY_WORKING_DIR . 'storage');
define('BOOTGLY_STORAGE_DIR', BOOTGLY_STORAGE_BASE . DIRECTORY_SEPARATOR);
@include($bootglyDir . '/vendor/autoload.php');
spl_autoload_register(function (string $class) {
   $file = implode('/', explode('\\', $class)) . '.php';
   @include(BOOTGLY_ROOT_DIR . $file);
});

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Benchmark;


Benchmark::$memory = true;

$Cache = new Cache([
   'driver' => 'file',
   'prefix' => 'bench:',
   'path' => sys_get_temp_dir() . '/bootgly-bench-cache',
]);

$Cache->clear();
(require __DIR__ . '/../../scenarios.php')($Cache);
$Cache->clear();

Benchmark::output();
