# ⏱️ Benchmark - Template Engine (`foreach` directive)

In this benchmark, we will compare the performance of the `foreach` directive in different template engines. The `foreach` directive is commonly used to iterate over a collection of data and generate dynamic content.

## 🔍 Context

The goal is to determine which template engine performs the best in terms of speed and efficiency when iterating over a large collection of data.

We will compare the performance of the `foreach` directive in the following template engines:

- Bootgly Template Engine
- Blade

The benchmark will focus on the rendering time of each template engine and will exclude any application processing within the loop.

The benchmark will be executed through a `CLI` interface.

The test involves iterating over a collection of `1,000,000 items`.

The start time in microtime is recorded just before the `foreach` directive begins iterating over the collection, and the end time is recorded immediately after the `foreach` directive finishes iterating. This approach ensures that other factors do not affect the accuracy of the results.

The total time taken is then calculated and displayed on the terminal.

It's important to note that the Bootgly template engine does not sacrifice any of the features available in the Blade's foreach directive. These features include:

- Access to the loop variable for additional information (like `loop->index`, `loop->count`, `loop->first`, `loop->last`, etc.)
- Ability to use `@continue` and `@break` directives within the loop

---

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

2) Change directory to `bootgly`:

```bash
cd bootgly
```

---

3) Create a custom user script called `foreach-bootgly_template.php` in `scripts/`:

```bash
nano scripts/foreach-bootgly_template.php
```

---

4) Put the code bellow:

```php
<?php

include __DIR__ . '/../autoload.php';

use Bootgly\ABI\Templates\Template;


$Template = new Template(
   <<<'TEMPLATE'
   <?php
   $started = microtime(true);
   ?>

   @foreach ($items as $item):
   @foreach;

   <?php
   $finished = microtime(true);

   echo ($finished - $started) . PHP_EOL;
   ?>
   TEMPLATE
);
$Template->render([
   'items' => range(0, 1000000)
]);
echo $Template->output;
?>
```

---

5) Register the script `foreach-bootgly_template.php` on script bootstrap file (`scripts/@.php`):

```bash
nano scripts/@.php
```

```php
<?php
return [
   'scripts' => [
      'built-in' => [ # Relative to scripts/ (bootgly's root directory)
         'http-server-cli',
         'tcp-server-cli',
         'tcp-client-cli',
      ],
      'imported' => [ # Relative to working directory (your root directory)
         'vendor/bin/phpstan'
      ],
      'user' => [ # Relative to scripts/ (your working directory)
         // Define your scripts filenames here
         'foreach-bootgly_template.php' # <<<------ HERE
      ]
   ]
];
```

---

6) Run the script

```bash
php scripts/foreach-bootgly_template.php
```

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

3) Edit the Laravel welcome template `welcome.blade.php`:

```bash
nano resources/views/welcome.blade.php
```

---

4) Replace the `welcome.blade.php` content with this:

```php
<?php
$started = microtime(true);
?>

@foreach ($items as $item)
@endforeach

<?php
$finished = microtime(true);

echo ($finished - $started) . PHP_EOL;
?>
```

---

5) Edit the web route `/` to pass `$items` to template:

```bash
nano routes/web.php
```

```php
Route::get('/', function () {
   $items = range(0, 1000000);
   return view('welcome',
   [
      'items' => $items,
   ]);
});
```

6) Run the script `public/index.php` to execute the web route `/`:

```bash
php public/index.php
```

</details>

---

## 📊 Results

> Bootgly Template Engine is ≈ 9x faster than Laravel Blade (without sacrificing features)

Framework | Result | Position
--- | --- | ---
Bootgly | 0.046s | 🥇 First (winner)
Laravel | 0.438s | 🥈 Second

---

By comparing the times taken by the foreach directive in the Bootgly Template Engine and Blade, we can gain insights into the relative performance of these two template engines when handling large collections of data. This information can be useful when choosing a template engine for projects that involve processing large amounts of data.

> [!WARNING]
> It's important to note that the exact numbers in the benchmark results may vary depending on the specific environment and resources where the benchmark is being run. Factors such as the hardware specifications, the operating system, the PHP version, and other running processes can all influence the performance of the template engines.
> 
> However, while the exact numbers may vary, the relative performance or the proportion between the results should remain consistent. That is, if the Bootgly Template Engine is faster than Laravel Blade in one environment, it should be faster in other environments as well, even if the exact speedup factor varies. This consistency in relative performance makes the benchmark a useful tool for comparing the efficiency of different template engines, regardless of the specific environment where they are run.