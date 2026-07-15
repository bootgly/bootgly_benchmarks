<?php

use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\Benchmarks\HTTP_Server_CLI\DatabaseParity;


$framework = dirname(__DIR__, 3) . '/bootgly/Bootgly';
require_once "{$framework}/ABI/Data/__String/Escapeable.php";
require_once "{$framework}/ABI/Data/__String/Escapeable/Text.php";
require_once "{$framework}/ABI/Data/__String/Escapeable/Text/Formattable.php";
require_once "{$framework}/ACI/Tests/Benchmark/Configs.php";
require_once "{$framework}/ACI/Tests/Benchmark/Opponent.php";
require_once "{$framework}/ACI/Tests/Benchmark/Runner.php";
require_once dirname(__DIR__, 2) . '/HTTP_Server_CLI/DatabaseParity.php';

$environment = [
   'BENCHMARK_HELP' => getenv('BENCHMARK_HELP'),
   'DB_POOL_MAX' => getenv('DB_POOL_MAX'),
];
$Check = static function (bool $condition, string $message): void {
   if ($condition === false) {
      throw new RuntimeException($message);
   }
};
$Make = static function (array $names): array {
   return array_map(
      static fn (string $name): Opponent => new Opponent($name, __FILE__),
      $names,
   );
};
$ExpectFailure = static function (callable $Callback, string $fragment) use ($Check): void {
   try {
      $Callback();
   }
   catch (InvalidArgumentException $Exception) {
      $Check(
         str_contains($Exception->getMessage(), $fragment),
         "Parity validation failed for an unexpected reason: {$Exception->getMessage()}",
      );

      return;
   }

   throw new RuntimeException("Parity validation unexpectedly accepted: {$fragment}");
};

try {
   putenv('BENCHMARK_HELP=1');
   putenv('DB_POOL_MAX');
   $Check(
      DatabaseParity::normalize('techempower') === null && getenv('DB_POOL_MAX') === false,
      'Help inspection mutated DB_POOL_MAX.',
   );

   putenv('BENCHMARK_HELP');
   $poolMax = DatabaseParity::normalize('techempower');
   $Check($poolMax === '1', 'An omitted TechEmpower DB_POOL_MAX did not normalize to 1.');
   $Check(getenv('DB_POOL_MAX') === '1', 'The normalized pool ceiling was not materialized in the environment.');

   $All = $Make([
      'Bootgly',
      'Swoole',
      'AMPHP',
      'ReactPHP',
      'Hyperf',
      'Workerman',
      'RoadRunner',
      'FrankenPHP',
      'Laravel (Octane)',
   ]);
   $Configs = Configs::parse([
      'opponents' => 'bootgly,swoole,amphp,reactphp,hyperf,workerman,roadrunner,frankenphp,laravel-octane',
      'loads' => 'techempower:3,4,5,6,7',
   ]);
   $Check(DatabaseParity::check($Configs), 'TechEmpower DB loads were not identified.');
   $Check(
      DatabaseParity::validate($Configs, $All, $poolMax) === 1,
      'The universal fixed-one/configurable intersection was rejected.',
   );

   putenv('DB_POOL_MAX=2');
   $poolMax = DatabaseParity::normalize('techempower');
   $PoolAware = $Make(['Bootgly', 'Swoole', 'AMPHP', 'ReactPHP', 'Hyperf']);
   $Configs = Configs::parse([
      'opponents' => 'bootgly,swoole,amphp,reactphp,hyperf',
      'loads' => 'techempower:3',
   ]);
   $Check(
      DatabaseParity::validate($Configs, $PoolAware, $poolMax) === 2,
      'A pool-aware-only selection did not accept DB_POOL_MAX=2.',
   );

   foreach (['Workerman', 'RoadRunner', 'FrankenPHP', 'Laravel (Octane)'] as $name) {
      $Configs = Configs::parse([
         'opponents' => Configs::slug($name),
         'loads' => 'techempower:4',
      ]);
      $ExpectFailure(
         static fn () => DatabaseParity::validate($Configs, $Make([$name]), '2'),
         $name,
      );
   }

   $Configs = Configs::parse([
      'opponents' => 'unclassified-server',
      'loads' => 'techempower:3',
   ]);
   $ExpectFailure(
      static fn () => DatabaseParity::validate(
         $Configs,
         $Make(['Unclassified Server']),
         '1',
      ),
      'Unclassified Server',
   );

   foreach (['0', '-1', '01', '1.0', '1e2', ' 1', (string) PHP_INT_MAX . '0'] as $invalid) {
      $Configs = Configs::parse([
         'opponents' => 'bootgly',
         'loads' => 'techempower:3',
      ]);
      $ExpectFailure(
         static fn () => DatabaseParity::validate($Configs, $Make(['Bootgly']), $invalid),
         'DB_POOL_MAX',
      );
   }

   $Configs = Configs::parse([
      'opponents' => 'unclassified-server',
      'loads' => 'techempower:1,2',
   ]);
   $Check(DatabaseParity::check($Configs) === false, 'Plaintext/JSON were classified as DB loads.');
   $Check(
      DatabaseParity::validate($Configs, $Make(['Unclassified Server']), '2') === 2,
      'A non-DB selection was incorrectly rejected by the DB capability policy.',
   );

   putenv('DB_POOL_MAX=7');
   $Check(
      DatabaseParity::normalize('benchmark') === null && getenv('DB_POOL_MAX') === '7',
      'The Bootgly-only benchmark set was modified by the cross-framework policy.',
   );

   foreach (['TCP_Client.php', 'HTTP_Client.php'] as $runnerFile) {
      $Runner = include dirname(__DIR__) . "/{$runnerFile}";
      $Runner->meta['db-pool-max'] = 1;
      $Configs = Configs::parse(['loads' => 'techempower:1,2']);
      $sections = $Runner->banner($Configs);
      $Check(
         ($sections['Database']['Pool max / worker'] ?? null) === '1'
            && ($sections['Database']['Parity'] ?? null) === 'Not applicable · no DB load selected',
         "{$runnerFile} did not expose the effective non-DB pool metadata clearly.",
      );

      $Runner->meta['db-pool-comparability'] = DatabaseParity::CONTRACT;
      $Configs = Configs::parse(['loads' => 'techempower:3']);
      $sections = $Runner->banner($Configs);
      $Check(
         ($sections['Database']['Parity'] ?? null) === 'Capability contract validated',
         "{$runnerFile} did not expose validated DB comparability.",
      );
   }
}
finally {
   foreach ($environment as $name => $value) {
      $value === false ? putenv($name) : putenv("{$name}={$value}");
   }
}

fwrite(STDOUT, "HTTP server DB parity proof: OK\n");
