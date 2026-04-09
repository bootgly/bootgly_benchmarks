<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Template_Engine — Bootgly Competitor
 * --------------------------------------------------------------------------
 *
 * Tests foreach directive with 1,000,000 items using Bootgly Template Engine.
 * Result is written to BENCHMARK_RESULT_FILE (set by the Runner).
 */

$bootglyDir = __DIR__ . '/../../../bootgly';

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

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Benchmark;


Benchmark::$memory = true;

$Template = new Template(
   <<<'TEMPLATE'
   <?php
   $started = microtime(true);
   ?>

   @foreach ($items as $item):
   @foreach;

   <?php
   $finished = microtime(true);
   ?>
   TEMPLATE
);

Benchmark::start('template');

$Template->render([
   'items' => range(0, 1000000)
]);

Benchmark::stop('template');
Benchmark::output('template');
