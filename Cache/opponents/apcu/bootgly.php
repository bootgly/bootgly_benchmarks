<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — APCu driver
 * --------------------------------------------------------------------------
 *
 * Runs the canonical cache workload against the APCu driver (per-process).
 * Exits non-zero (→ N/A) when ext-apcu is missing or disabled on CLI.
 */

// ? APCu unavailable on CLI → N/A
if ( !extension_loaded('apcu') || !filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL) ) {
   exit(1);
}

$bootglyDir = __DIR__ . '/../../../../bootgly';

// @ Minimal autoload (no platform boot)
define('BOOTGLY_ROOT_BASE', $bootglyDir);
define('BOOTGLY_ROOT_DIR', $bootglyDir . DIRECTORY_SEPARATOR);
define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
@include($bootglyDir . '/vendor/autoload.php');
spl_autoload_register(function (string $class) {
   $file = implode('/', explode('\\', $class)) . '.php';
   @include(BOOTGLY_ROOT_DIR . $file);
});

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Benchmark;


Benchmark::$memory = true;

$Cache = new Cache([
   'driver' => 'apcu',
   'prefix' => 'bench:',
]);

$Cache->clear();
(require __DIR__ . '/../../scenarios.php')($Cache);
$Cache->clear();

Benchmark::output();
