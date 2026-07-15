<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — Database parity policy
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Benchmarks\HTTP_Server_CLI;


use Bootgly\ACI\Tests\Benchmark\Configs;
use Bootgly\ACI\Tests\Benchmark\Opponent;
use InvalidArgumentException;


final class DatabaseParity
{
   public const string CONTRACT = 'capability-validated-v1';

   /**
    * Materialize the canonical TechEmpower pool ceiling when it was omitted.
    * Explicit values remain unmodified until validate() can reject malformed
    * input through the normal benchmark error path.
    */
   public static function normalize (string $loadSet): null|string
   {
      if (
         getenv('BENCHMARK_HELP') === '1'
         || ($loadSet !== 'techempower' && $loadSet !== 'benchmark')
      ) {
         return null;
      }

      $poolMax = getenv('DB_POOL_MAX');
      if (($poolMax === false || $poolMax === '') && $loadSet === 'techempower') {
         $poolMax = '1';
         putenv("DB_POOL_MAX={$poolMax}");
      }

      return $poolMax === false || $poolMax === '' ? null : $poolMax;
   }

   /**
    * Whether the concrete selection includes a TechEmpower database load.
    */
   public static function check (Configs $Configs): bool
   {
      if ($Configs->loadSet !== 'techempower') {
         return false;
      }

      if ($Configs->loads === null) {
         return true;
      }

      return array_intersect($Configs->loads, [3, 4, 5, 6, 7]) !== [];
   }

   /**
    * Validate the requested per-worker pool ceiling against the selected
    * opponents' inspected implementation capabilities.
    *
    * @param array<Opponent> $Opponents
    */
   public static function validate (
      Configs $Configs,
      array $Opponents,
      null|string $poolMax,
   ): null|int
   {
      if ($poolMax === null) {
         if (self::select($Configs)) {
            throw new InvalidArgumentException(
               'Worker-aware database readiness for benchmark loads requires '
               . 'an explicit DB_POOL_MAX=1.'
            );
         }

         return null;
      }

      if (preg_match('/^[1-9][0-9]*$/D', $poolMax) !== 1) {
         throw new InvalidArgumentException(
            "DB_POOL_MAX must be a positive base-10 integer; received "
            . var_export($poolMax, true) . '.'
         );
      }

      $effective = filter_var(
         $poolMax,
         FILTER_VALIDATE_INT,
         ['options' => ['min_range' => 1]],
      );
      if ($effective === false) {
         throw new InvalidArgumentException(
            "DB_POOL_MAX is outside the supported integer range: {$poolMax}."
         );
      }

      // # Canonicalize what every subsequently spawned server receives.
      putenv("DB_POOL_MAX={$effective}");

      if (self::prove($Configs) && $effective !== 1) {
         throw new InvalidArgumentException(
            'Worker-aware database readiness currently '
            . "supports only DB_POOL_MAX=1; received {$effective}."
         );
      }

      if (self::check($Configs) === false) {
         return $effective;
      }

      // # This is deliberately fail-closed. A newly registered opponent must
      //   be inspected and classified before it can publish a DB comparison.
      $capabilities = [
         'bootgly'         => 'configurable',
         'swoole'          => 'configurable',
         'amphp'           => 'configurable',
         'reactphp'        => 'configurable',
         'hyperf'          => 'configurable',
         'workerman'       => 'fixed-one',
         'roadrunner'      => 'fixed-one',
         'frankenphp'      => 'fixed-one',
         'laravel-octane'  => 'fixed-one',
      ];
      $selected = $Configs->opponents === null
         ? null
         : array_map(Configs::slug(...), $Configs->opponents);
      $unsupported = [];
      $unclassified = [];

      foreach ($Opponents as $Opponent) {
         $slug = Configs::slug($Opponent->name);
         if ($selected !== null && in_array($slug, $selected, true) === false) {
            continue;
         }

         $capability = $capabilities[$slug] ?? null;
         if ($capability === null) {
            $unclassified[] = $Opponent->name;
            continue;
         }
         if ($effective !== 1 && $capability === 'fixed-one') {
            $unsupported[] = $Opponent->name;
         }
      }

      if ($unclassified !== []) {
         throw new InvalidArgumentException(
            'Database pool parity is unproved for opponent(s): '
            . implode(', ', $unclassified)
            . '. Classify their effective per-worker connection ceiling before benchmarking DB loads.'
         );
      }
      if ($unsupported !== []) {
         throw new InvalidArgumentException(
            "DB_POOL_MAX={$effective} cannot be compared fairly with fixed-one-connection opponent(s): "
            . implode(', ', $unsupported)
            . '. Use DB_POOL_MAX=1 or select only pool-aware opponents.'
         );
      }

      return $effective;
   }

   /** Whether the Bootgly-only benchmark selection includes a database load. */
   private static function select (Configs $Configs): bool
   {
      if ($Configs->loadSet !== 'benchmark') {
         return false;
      }

      if (
         $Configs->opponents !== null
         && in_array(
            'bootgly',
            array_map(Configs::slug(...), $Configs->opponents),
            true,
         ) === false
      ) {
         return false;
      }

      if ($Configs->loads === null) {
         return true;
      }

      return array_intersect($Configs->loads, range(10, 18)) !== [];
   }

   /** Whether the selected load requires live database-slot readiness proof. */
   private static function prove (Configs $Configs): bool
   {
      if ($Configs->loadSet === 'benchmark') {
         return self::select($Configs);
      }
      if ($Configs->loadSet !== 'techempower') {
         return false;
      }

      if ($Configs->loads === null) {
         return true;
      }

      // `cached-queries` (7) is served from a per-worker in-memory dataset and
      // deliberately has no live database resource declaration.
      return array_intersect($Configs->loads, [3, 4, 5, 6]) !== [];
   }
}
