<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — Shared driver
 * --------------------------------------------------------------------------
 *
 * Runs the canonical cache workload against the Shared driver (System V
 * shared memory + semaphore, cross-worker). Exits non-zero (→ N/A) when the
 * sysvshm/sysvsem extensions are unavailable.
 */

// ? System V IPC unavailable → N/A
if ( !function_exists('shm_attach') || !function_exists('sem_get') ) {
   exit(1);
}

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
   'driver' => 'shared',
   'prefix' => 'bench:',
]);

$Cache->clear();
(require __DIR__ . '/../../scenarios.php')($Cache);
$Cache->clear();

Benchmark::output();
