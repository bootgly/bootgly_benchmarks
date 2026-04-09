<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Progress_Bar — Laravel/Symfony Competitor
 * --------------------------------------------------------------------------
 *
 * Renders 5000 Progress Bar iterations using Symfony ProgressBar (via Laravel).
 * Outputs JSON benchmark result to stdout.
 *
 * Setup: run `composer install` inside artifacts/laravel/ first.
 */

$artifactDir = __DIR__ . '/../artifacts/laravel';
$bootglyDir = __DIR__ . '/../../../bootgly';

require $artifactDir . '/vendor/autoload.php';

// @ Minimal Bootgly autoload for Benchmark class
define('BOOTGLY_ROOT_BASE', $bootglyDir);
define('BOOTGLY_ROOT_DIR', $bootglyDir . DIRECTORY_SEPARATOR);
define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
spl_autoload_register(function (string $class) {
   $file = implode('/', explode('\\', $class)) . '.php';
   @include(BOOTGLY_ROOT_DIR . $file);
});

use Bootgly\ACI\Tests\Benchmark;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;


Benchmark::$memory = true;

$output = new ConsoleOutput();
$progressBar = new ProgressBar($output, 5000, 0);

$progressBar->setFormat(<<<'TEMPLATE'
%message%
%current%/%max% [%bar%] %percent%%
⏱️ %elapsed% / 🏁 %remaining%
TEMPLATE);

$progressBar->setRedrawFrequency(1);
$progressBar->minSecondsBetweenRedraws(0);
$progressBar->setBarWidth(10);
$progressBar->setBarCharacter('❤️');
$progressBar->setEmptyBarCharacter('🖤');
$progressBar->setProgressCharacter('');

Benchmark::start('progress');

$progressBar->start();

$i = 0;
while ($i++ < 5000) {
   if ($i === 1) $progressBar->setMessage('Performing progress!');
   if ($i === 2500) $progressBar->setMessage('There\'s only half left...');
   if ($i === 4999) $progressBar->setMessage('Finished!!!');
   $progressBar->advance();
}

$progressBar->finish();

Benchmark::stop('progress');
Benchmark::output('progress');
