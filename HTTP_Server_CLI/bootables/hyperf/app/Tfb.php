<?php

declare(strict_types=1);

namespace App;


use const ENT_QUOTES;
use function asort;
use function count;
use function getenv;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function mt_rand;
use PDO;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;


/**
 * TechEmpower benchmark DB helper for Hyperf.
 *
 * Uses a per-worker Swoole PDOPool (coroutine connection pool) — the same
 * topology as the Swoole opponent — so Hyperf's coroutine HTTP server gets
 * concurrent, non-blocking PostgreSQL access. Pool size = DB_POOL_MAX (default
 * 1), so workers × 1 connection matches the pooled servers' per-worker DB
 * footprint (parity with Bootgly DB_POOL_MAX=1).
 */
final class Tfb
{
   private static null|PDOPool $Pool = null;
   /** @var null|array<int,array{id:int,randomNumber:int}> */
   private static null|array $CachedWorlds = null;

   public static function pool (): PDOPool
   {
      // ? Lazy per-worker singleton — created in the first request's coroutine.
      if (self::$Pool === null) {
         $port = getenv('DB_PORT') ?: '5432';
         $poolMax = getenv('DB_POOL_MAX') ?: '1';

         $Config = (new PDOConfig)
            ->withDriver('pgsql')
            ->withHost(getenv('DB_HOST') ?: '127.0.0.1')
            ->withPort(is_numeric($port) ? (int) $port : 5432)
            ->withDbName(getenv('DB_NAME') ?: 'bootgly')
            ->withUsername(getenv('DB_USER') ?: 'postgres')
            ->withPassword((string) (getenv('DB_PASS') ?: ''))
            ->withOptions([
               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
               PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_EMULATE_PREPARES => false,
            ]);

         self::$Pool = new PDOPool($Config, max(1, (int) $poolMax));
      }

      return self::$Pool;
   }

   public static function count (mixed $value): int
   {
      if (is_array($value)) {
         $value = $value[0] ?? 1;
      }

      // : Missing / non-integer / < 1 → 1; > 500 → 500 (TFB clamp)
      return max(1, min(500, (int) $value));
   }

   public static function db (): array
   {
      $PDO = self::pool()->get();

      try {
         $Statement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');

         return self::world($Statement, mt_rand(1, 10000));
      }
      finally {
         self::pool()->put($PDO);
      }
   }

   public static function query (int $queries): array
   {
      $PDO = self::pool()->get();

      try {
         $Statement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
         $Worlds = [];

         while ($queries-- > 0) {
            $Worlds[] = self::world($Statement, mt_rand(1, 10000));
         }

         return $Worlds;
      }
      finally {
         self::pool()->put($PDO);
      }
   }

   public static function fortunes (): string
   {
      $PDO = self::pool()->get();

      try {
         $Statement = $PDO->prepare('SELECT id, message FROM Fortune');
         $Statement->execute();
         $rows = $Statement->fetchAll(PDO::FETCH_ASSOC);
      }
      finally {
         self::pool()->put($PDO);
      }

      $Fortunes = [0 => 'Additional fortune added at request time.'];
      foreach ($rows as $row) {
         $Fortunes[(int) $row['id']] = (string) $row['message'];
      }
      asort($Fortunes);

      $html = '';
      foreach ($Fortunes as $id => $message) {
         $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
         $html .= "<tr><td>{$id}</td><td>{$message}</td></tr>";
      }

      return "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$html}</table></body></html>";
   }

   public static function updates (int $queries): array
   {
      $PDO = self::pool()->get();

      try {
         $Statement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
         $Worlds = [];

         while ($queries-- > 0) {
            $World = self::world($Statement, mt_rand(1, 10000));
            $World['randomNumber'] = mt_rand(1, 10000);
            $Worlds[] = $World;
         }

         self::update($PDO, $Worlds);

         return $Worlds;
      }
      finally {
         self::pool()->put($PDO);
      }
   }

   public static function cached (int $count): array
   {
      // ? Prime the per-worker in-memory cache once from CachedWorld (no DB on the hot path after).
      if (self::$CachedWorlds === null) {
         $PDO = self::pool()->get();

         try {
            $Statement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld');
            $Statement->execute();
            $rows = $Statement->fetchAll(PDO::FETCH_ASSOC);
         }
         finally {
            self::pool()->put($PDO);
         }

         $Cache = [];
         foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $Cache[$id] = [
               'id' => $id,
               'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
            ];
         }

         self::$CachedWorlds = $Cache;
      }

      $max = count(self::$CachedWorlds);
      $Worlds = [];

      while ($count-- > 0) {
         $Worlds[] = self::$CachedWorlds[mt_rand(1, $max)] ?? null;
      }

      return $Worlds;
   }

   private static function world (object $Statement, int $id): array
   {
      $Statement->execute([$id]);
      $row = $Statement->fetch(PDO::FETCH_ASSOC) ?: [];

      return [
         'id' => (int) ($row['id'] ?? 0),
         'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
      ];
   }

   private static function update (object $PDO, array $Worlds): void
   {
      if ($Worlds === []) {
         return;
      }

      $cases = [];
      $ids = [];
      $parameters = [];

      foreach ($Worlds as $World) {
         $cases[] = 'WHEN ?::integer THEN ?::integer';
         $parameters[] = $World['id'];
         $parameters[] = $World['randomNumber'];
      }

      foreach ($Worlds as $World) {
         $ids[] = '?::integer';
         $parameters[] = $World['id'];
      }

      $Statement = $PDO->prepare('UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')');
      $Statement->execute($parameters);
   }
}
