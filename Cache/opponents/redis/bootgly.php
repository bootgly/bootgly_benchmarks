<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Cache — Redis driver (blocking)
 * --------------------------------------------------------------------------
 *
 * Runs the canonical cache workload against the Redis driver (native RESP, or
 * the ext-redis fast-path when loaded). Uses an isolated database (15) and a
 * 'bench:' prefix. Exits non-zero (→ N/A) when no server is reachable.
 */

// ? Redis server unreachable → N/A
$Probe = @stream_socket_client('tcp://127.0.0.1:6379', $errno, $errstr, 0.5);
if ($Probe === false) {
   exit(1);
}
fclose($Probe);

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
   'driver' => 'redis',
   'prefix' => 'bench:',
   'host' => '127.0.0.1',
   'port' => 6379,
   'database' => 15,
]);

$Cache->clear();
(require __DIR__ . '/../../scenarios.php')($Cache);
$Cache->clear();

Benchmark::output();
