# ⏱️ Benchmark - Progress Bar (WIP)

This document contains informations about benchmarking the **Progress Bar** component, a well-known component for the CLI.

| ![](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-Progress-Bar-component.png "Render 6x faster than Symfony / Laravel") |
|:--:| 
| *Progress component (with Bar) - Render ≈ 7x faster than Symfony / Laravel* |

## 🔍 Context

The context of this benchmark is framework performance only, more specifically how fast the CLI component can render units of progress in a while with a fixed # of loops.

- No application processing will be executed within the loop.
- Settings that limit rendering will also be removed for maximum performance.

**Interface:**

CLI

**Platform:**

-- None --

**Workable:**

-- None --

---

## 📋 Instructions

<details>
  <summary><b>🥇 Benchmark on Bootgly</b></summary><br>

1) Clone the Bootgly base platform repository:
```bash
git clone https://github.com/bootgly/bootgly.git
```
---
2) Change directory to `bootgly/scripts`:
```bash
cd bootgly/scripts
```
---
3) Create a temp script:
```bash
nano progress.php
```
---
4) Copy and paste the Bootgly Progress Bar benchmarking code:
```php
<?php
namespace scripts;


require __DIR__ . '/../autoload.php';


use Bootgly\CLI;
use Bootgly\CLI\Terminal\components\Progress\Progress;


$Output = CLI::$Terminal->Output;

$Progress = new Progress($Output);
// * Config
// @
$Progress->throttle = 0.0; // @ Remove any limit

// * Data
// @
$Progress->total = 250000;
// ! Templating
$Progress->template = <<<'TEMPLATE'
@description;
@current;/@total; [@bar;] @percent;%
⏱️ @elapsed;s - 🏁 @eta;s - 📈 @rate; loops/s
TEMPLATE;

// ! Bar
// * Config
$Progress->Bar->units = 10;
// * Data
$Progress->Bar->Symbols->incomplete = '🖤';
$Progress->Bar->Symbols->current = '';
$Progress->Bar->Symbols->complete = '❤️';

$Progress->start();

$i = 0;
while ($i++ < 250000) {
   if ($i === 1) {
      $Progress->describe('@#red: Performing progress! @;');
   }
   if ($i === 125000) {
      $Progress->describe('@#yellow: There\'s only half left... @;');
   }
   if ($i === 249999) {
      $Progress->describe('@#green: Finished!!! @;');
   }

   $Progress->advance();
}

$Progress->finish();
```

Execute the script:
```bash
php progress.php
```
---
5) Wait the progress and check the time spent to complete 250K iterations:

![bootgly-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/assets/9668277/b5a70e60-5aac-4991-8405-f8b28a94f4cf)
</details>



<details>
  <summary><b>🥈 Benchmark on Laravel</b></summary><br>

1) Create a new project using Composer:

```bash
composer create-project --prefer-dist laravel/laravel laravel
```
---
2) Change directory to `laravel`:
```bash
cd laravel
```
---
3) Create new PHP script on root:
```bash
nano progress_bar.php
```
---
4) Copy and paste the Laravel Progress Bar benchmarking code:
```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';


use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

$output = new ConsoleOutput();

// creates a new progress bar (50 units)
$progressBar = new ProgressBar($output, 250000, 0);

$progressBar->setFormat(<<<'TEMPLATE'
%message%
%current%/%max% [%bar%] %percent%%
⏱️ %elapsed% / 🏁 %remaining%
TEMPLATE);

$progressBar->setRedrawFrequency(1);
$progressBar->minSecondsBetweenRedraws(0);

$progressBar->setBarWidth(10);

// Bar symbols
$progressBar->setBarCharacter('❤️');
$progressBar->setEmptyBarCharacter('🖤');
$progressBar->setProgressCharacter('');

// starts and displays the progress bar
$progressBar->start();

$i = 0;
while ($i++ < 250000) {
   if ($i === 1) {
      $progressBar->setMessage('Performing progress!');
   }
   if ($i === 125000) {
      $progressBar->setMessage('There\'s only half left...');
   }
   if ($i === 249999) {
      $progressBar->setMessage('Finished!!!');
   }

   $progressBar->advance();
}

// ensures that the progress bar is at 100%
$progressBar->finish();

echo PHP_EOL;
```
---
5) Wait the progress and check the time spent to complete 250K iterations:

![laravel-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/assets/9668277/eaae01ac-a5b7-48c6-a5cf-48766b407d8f)
</details>

---

## 📊 Results

> Bootgly Progress Bar is ≈ 7x faster than Laravel / Symfony Progress Bar

### Bootgly Progress Bar
![bootgly-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/assets/9668277/b5a70e60-5aac-4991-8405-f8b28a94f4cf)

### Laravel/Symfony Progress Bar
![laravel-progress_bar-benchmark 1](https://github.com/bootgly/bootgly_benchmarks/assets/9668277/eaae01ac-a5b7-48c6-a5cf-48766b407d8f)

Framework | Result | Position
--- | --- | ---
Bootgly | 6.49s | 🥇 First (winner)
Laravel/Symfon | 45s | 🥈 Second
