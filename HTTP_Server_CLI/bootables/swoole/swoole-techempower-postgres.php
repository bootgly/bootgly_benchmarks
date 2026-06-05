<?php

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Coroutine\WaitGroup;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

if (extension_loaded('swoole') === false || extension_loaded('pdo_pgsql') === false) {
   fwrite(STDERR, "Swoole Database competitor requires swoole and pdo_pgsql extensions.\n");
   exit(1);
}

final class SwooleDatabaseBenchmark
{
   private static PDOPool $Pool;

   public static function init (): void
   {
      $port = self::env('DB_PORT', '5432');
      $poolMax = self::env('DB_POOL_MAX', '1');
      $poolSize = is_numeric($poolMax) ? max(1, (int) $poolMax) : 1;

      $Config = (new PDOConfig)
         ->withDriver('pgsql')
         ->withHost(self::env('DB_HOST', '127.0.0.1'))
         ->withPort(is_numeric($port) ? (int) $port : 5432)
         ->withDbName(self::env('DB_NAME', 'bootgly'))
         ->withUsername(self::env('DB_USER', 'postgres'))
         ->withPassword(self::env('DB_PASS', ''))
         ->withOptions([
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
         ]);

      self::$Pool = new PDOPool($Config, $poolSize);
   }

   public static function ping (): string
   {
      return self::fetchAll('SELECT 1 AS ok');
   }

   public static function db (): string
   {
      return self::withDatabase(static function ($Database): string {
         $Statement = $Database->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
         $World = self::fetchWorld($Statement, mt_rand(1, 10000));

         return json_encode($World, JSON_NUMERIC_CHECK) ?: '{}';
      });
   }

   public static function query (int $queries): string
   {
      return self::withDatabase(static function ($Database) use ($queries): string {
         $Statement = $Database->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
         $Worlds = [];

         while ($queries-- > 0) {
            $Worlds[] = self::fetchWorld($Statement, mt_rand(1, 10000));
         }

         return json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
      });
   }

   public static function fortunes (): string
   {
      return self::withDatabase(static function ($Database): string {
         $Statement = $Database->prepare('SELECT id, message FROM Fortune');
         $Statement->execute();
         $rows = $Statement->fetchAll(PDO::FETCH_ASSOC);
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
      });
   }

   public static function updates (int $queries): string
   {
      return self::withDatabase(static function ($Database) use ($queries): string {
         $Statement = $Database->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
         $Worlds = [];

         while ($queries-- > 0) {
            $World = self::fetchWorld($Statement, mt_rand(1, 10000));
            $World['randomNumber'] = mt_rand(1, 10000);
            $Worlds[] = $World;
         }

         self::update($Database, $Worlds);

         return json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
      });
   }

   public static function parameters (): string
   {
      return self::fetchAll('SELECT ?::int AS value, ?::text AS label', [42, 'bootgly']);
   }

   public static function pool (): string
   {
      $rows = [];
      $errors = [];
      $WaitGroup = new WaitGroup;

      foreach ([1, 2, 3] as $index => $value) {
         $WaitGroup->add();

         go(static function () use ($WaitGroup, &$rows, &$errors, $index, $value): void {
            try {
               $result = self::rows('SELECT ?::int AS value', [$value]);
               if (isset($result[0])) {
                  $rows[$index] = $result[0];
               }
            }
            catch (Throwable $Throwable) {
               $errors[] = $Throwable->getMessage();
            }
            finally {
               $WaitGroup->done();
            }
         });
      }

      $WaitGroup->wait();

      if ($rows !== []) {
         ksort($rows);
         $rows = array_values($rows);
      }

      return json_encode([
         'rows' => $rows,
         'errors' => $errors,
      ], JSON_NUMERIC_CHECK) ?: '{}';
   }

   public static function sleep (): string
   {
      return self::fetchAll('SELECT pg_sleep(0.05), ?::int AS value', [42]);
   }

   private static function fetchAll (string $sql, array $parameters = []): string
   {
      return json_encode(self::rows($sql, $parameters), JSON_NUMERIC_CHECK) ?: '[]';
   }

   private static function rows (string $sql, array $parameters = []): array
   {
      $Database = self::$Pool->get();

      try {
         $Statement = $Database->prepare($sql);
         $Statement->execute($parameters);

         return $Statement->fetchAll(PDO::FETCH_ASSOC);
      }
      finally {
         self::$Pool->put($Database);
      }
   }

   private static function withDatabase (callable $Callback): mixed
   {
      $Database = self::$Pool->get();

      try {
         return $Callback($Database);
      }
      finally {
         self::$Pool->put($Database);
      }
   }

   private static function fetchWorld ($Statement, int $id): array
   {
      $Statement->execute([$id]);
      $row = $Statement->fetch(PDO::FETCH_ASSOC) ?: [];

      return [
         'id' => (int) ($row['id'] ?? 0),
         'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
      ];
   }

   private static function update ($Database, array $Worlds): void
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

      $Statement = $Database->prepare('UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')');
      $Statement->execute($parameters);
   }

   public static function queryCount (Request $Request): int
   {
      $queries = $Request->get['queries'] ?? 1;

      if (is_array($queries)) {
         $queries = $queries[0] ?? 1;
      }

      return max(1, min(500, (int) $queries));
   }

   private static function env (string $name, string $default): string
   {
      $value = getenv($name);

      return $value === false || $value === '' ? $default : $value;
   }
}

$port = getenv('SERVER_PORT') ?: '8082';
$workers = getenv('SERVER_WORKER_NUM') ?: (string) max(1, (int) (shell_exec('nproc 2>/dev/null') ?: 1) / 2);

$Server = new Server('0.0.0.0', is_numeric($port) ? (int) $port : 8082, SWOOLE_PROCESS);
$Server->set([
   'worker_num' => is_numeric($workers) ? max(1, (int) $workers) : 1,
   'daemonize' => true,
   'log_file' => '/dev/null',
   'log_level' => SWOOLE_LOG_ERROR,
   'enable_coroutine' => true,
   'enable_reuse_port' => true,
   'hook_flags' => SWOOLE_HOOK_ALL,
   'http_compression' => false,
   'open_tcp_nodelay' => true,
]);

$Server->on('workerStart', static function (): void {
   SwooleDatabaseBenchmark::init();
});

$Server->on('request', static function (Request $Request, Response $Response): void {
   $path = $Request->server['request_uri'] ?? '/';

   // @ TechEmpower /plaintext + /json: static, no DB. Handle early.
   if ($path === '/plaintext') {
      $Response->header('Content-Type', 'text/plain');
      $Response->end('Hello, World!');

      return;
   }
   if ($path === '/json') {
      $Response->header('Content-Type', 'application/json');
      $Response->end('{"message":"Hello, World!"}');

      return;
   }

   try {
      $contentType = 'application/json';
      $body = match ($path) {
         '/db' => SwooleDatabaseBenchmark::db(),
         '/query' => SwooleDatabaseBenchmark::query(SwooleDatabaseBenchmark::queryCount($Request)),
         '/fortunes' => SwooleDatabaseBenchmark::fortunes(),
         '/updates' => SwooleDatabaseBenchmark::updates(SwooleDatabaseBenchmark::queryCount($Request)),
         default => null,
      };

      if ($body === null) {
         $Response->status(404);
         $Response->end('Not Found');

         return;
      }

      if ($path === '/fortunes') {
         $contentType = 'text/html; charset=utf-8';
      }

      $Response->header('Content-Type', $contentType);
      $Response->end($body);
   }
   catch (Throwable $Throwable) {
      $Response->status(500);
      $Response->end($Throwable->getMessage());
   }
});

$Server->start();