<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Progress_Bar — Bootgly Competitor
 * --------------------------------------------------------------------------
 *
 * Renders 5000 Progress Bar iterations using Bootgly CLI.
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

use Bootgly\ACI\Tests\Benchmark;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Components\Progress;


Benchmark::$memory = true;

// @ Initialize Terminal (real STDOUT — visual rendering)
$Terminal = new Terminal;

$Progress = new Progress($Terminal->Output);
$Progress->throttle = 0.0;
$Progress->total = 5000;
$Progress->template = <<<'TEMPLATE'
@description;
@current;/@total; [@bar;] @percent;%
⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
TEMPLATE;

$Bar = $Progress->Bar;
$Bar->units = 10;
$Bar->Symbols->incomplete = '🖤';
$Bar->Symbols->current = '';
$Bar->Symbols->complete = '❤️';

Benchmark::start('progress');

$Progress->start();

$i = 0;
while ($i++ < 5000) {
   if ($i === 1)
      $Progress->describe('@#red: Performing progress! @;');
   if ($i === 2500)
      $Progress->describe('@#yellow: There\'s only half left... @;');
   if ($i === 4999)
      $Progress->describe('@#green: Finished!!! @;');
   $Progress->advance();
}

$Progress->finish();

Benchmark::stop('progress');
Benchmark::output('progress');
