<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — Template_Engine — Laravel/Blade Competitor
 * --------------------------------------------------------------------------
 *
 * Tests foreach directive with 1,000,000 items using Laravel Blade.
 * Result is written to BENCHMARK_RESULT_FILE (set by the Runner).
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
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;


Benchmark::$memory = true;

// @ Setup Blade standalone
$viewsPath = $artifactDir . '/views';
$cachePath = $artifactDir . '/cache';

if ( !is_dir($viewsPath) ) mkdir($viewsPath, 0775, true);
if ( !is_dir($cachePath) ) mkdir($cachePath, 0775, true);

// @ Create view file
file_put_contents($viewsPath . '/bench.blade.php', <<<'BLADE'
@foreach ($items as $item)
@endforeach
BLADE);

$filesystem = new Filesystem;
$compiler = new BladeCompiler($filesystem, $cachePath);
$resolver = new EngineResolver;
$resolver->register('blade', function () use ($compiler) {
   return new CompilerEngine($compiler);
});
$finder = new FileViewFinder($filesystem, [$viewsPath]);
$factory = new Factory($resolver, $finder, new Dispatcher);

Benchmark::start('template');

$factory->make('bench', ['items' => range(0, 1000000)])->render();

Benchmark::stop('template');
Benchmark::output('template');
