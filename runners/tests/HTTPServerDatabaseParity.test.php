<?php

use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\Benchmarks\HTTP_Server_CLI\DatabaseParity;


require_once dirname(__DIR__, 2) . '/HTTP_Server_CLI/DatabaseParity.php';

return new Specification(
   description: 'It should enforce the HTTP server database comparability contract',
   test: static function (): bool
   {
$environment = [
   'BENCHMARK_HELP' => getenv('BENCHMARK_HELP'),
   'DB_POOL_MAX' => getenv('DB_POOL_MAX'),
];
$Check = static function (bool $condition, string $message): void {
   if ($condition === false) {
      throw new AssertionError($message);
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

   throw new AssertionError("Parity validation unexpectedly accepted: {$fragment}");
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
   $ExpectFailure(
      static fn () => DatabaseParity::validate($Configs, $PoolAware, $poolMax),
      'supports only DB_POOL_MAX=1',
   );

   $Configs = Configs::parse([
      'opponents' => 'bootgly,swoole',
      'loads' => 'techempower:7',
   ]);
   $Check(
      DatabaseParity::validate($Configs, $Make(['Bootgly', 'Swoole']), $poolMax) === 2,
      'The in-memory cached-queries load was incorrectly treated as live pool-slot proof.',
   );

   foreach (['Workerman', 'RoadRunner', 'FrankenPHP', 'Laravel (Octane)'] as $name) {
      $Configs = Configs::parse([
         'opponents' => Configs::slug($name),
         'loads' => 'techempower:4',
      ]);
      $ExpectFailure(
         static fn () => DatabaseParity::validate($Configs, $Make([$name]), '2'),
         'supports only DB_POOL_MAX=1',
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

   putenv('DB_POOL_MAX');
   $Check(
      DatabaseParity::normalize('benchmark') === null && getenv('DB_POOL_MAX') === false,
      'An omitted benchmark DB_POOL_MAX was materialized before load selection.',
   );

   $Configs = Configs::parse([
      'opponents' => 'bootgly',
      'loads' => 'benchmark:10',
   ]);
   $ExpectFailure(
      static fn () => DatabaseParity::validate($Configs, $Make(['Bootgly']), null),
      'explicit DB_POOL_MAX=1',
   );
   $ExpectFailure(
      static fn () => DatabaseParity::validate($Configs, $Make(['Bootgly']), '2'),
      'supports only DB_POOL_MAX=1',
   );
   $Check(
      DatabaseParity::validate($Configs, $Make(['Bootgly']), '1') === 1,
      'A benchmark DB load rejected the only currently attestable pool ceiling.',
   );

   foreach (['benchmark:10', 'benchmark:*'] as $loads) {
      $Configs = Configs::parse([
         'opponents' => 'swoole',
         'loads' => $loads,
      ]);
      $Check(
         DatabaseParity::validate($Configs, $Make(['Swoole']), null) === null,
         "A {$loads} selection without Bootgly incorrectly required its DB resource contract.",
      );
   }

   putenv('DB_POOL_MAX=7');
   $Check(
      DatabaseParity::normalize('benchmark') === '7' && getenv('DB_POOL_MAX') === '7',
      'An explicit benchmark DB_POOL_MAX was not retained for validation.',
   );
   $Configs = Configs::parse([
      'opponents' => 'bootgly',
      'loads' => 'benchmark:1,2',
   ]);
   $Check(
      DatabaseParity::validate($Configs, $Make(['Bootgly']), '7') === 7,
      'A non-DB benchmark selection was constrained by resource attestation.',
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

   return true;
}
finally {
   foreach ($environment as $name => $value) {
      $value === false ? putenv($name) : putenv("{$name}={$value}");
   }
}
   },
);
