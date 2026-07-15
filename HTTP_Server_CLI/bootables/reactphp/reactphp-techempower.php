<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — ReactPHP TechEmpower bootable
 * --------------------------------------------------------------------------
 *
 * Async PostgreSQL TechEmpower server (7 canonical routes) on ReactPHP:
 *   /plaintext  /json  /db  /query  /fortunes  /updates  /cached-queries
 *
 * Fully async — the event loop is NEVER blocked on the DB. PgAsync emits rows
 * as RxPHP Observables; we bridge each query to a React promise with
 * ->toArray()->toPromise() and combine the N queries of /query, /updates and
 * /cached-queries with React\Promise\all() before resolving the HTTP response.
 *
 * Worker model: the process
 * forks BOOTGLY_WORKERS children; EACH child runs its OWN React event loop and
 * binds 0.0.0.0:8082 with SO_REUSEPORT, so the kernel load-balances accepts
 * across the workers. The parent only reaps. ONE PgAsync\Client per worker is
 * created AFTER the fork (so each child owns its connection + loop).
 *
 * Response bodies/headers/SQL match the Workerman TechEmpower reference
 * (workerman-techempower-postgres.php) byte-for-byte. The ONLY difference is
 * the placeholder dialect: PgAsync is a native PostgreSQL client and uses
 * numbered placeholders ($1, $2, …) instead of PDO's `?` — the projected
 * columns, the batched UPDATE ... CASE id ... structure and the ::integer
 * casts are otherwise identical, so the emitted rows (and JSON) are identical.
 *
 * Env: DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS, BOOTGLY_WORKERS. Port = 8082.
 *
 * Usage: php reactphp-techempower.php
 */

require_once 'vendor/autoload.php';
require_once dirname(__DIR__) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;
use PgAsync\Client;

use function React\Promise\all;
use function React\Promise\resolve;

// ! Worker count — BOOTGLY_WORKERS or half the host CPUs (min 1).
$workers = (int) (getenv('BOOTGLY_WORKERS') ?: 0);
if ($workers < 1) {
   $workers = max(1, (int) ((int) (shell_exec('nproc 2>/dev/null') ?: 1) / 2));
}

$host = '0.0.0.0';
$port = 8082;

// @ Fork exactly $workers children — EACH child runs its own React loop and
//   serves; the parent only waits/reaps (it does NOT serve). A child breaks
//   out of this loop to serve() immediately.
$Pids = [];
for ($i = 0; $i < $workers; $i++) {
   $pid = pcntl_fork();

   if ($pid === 0) {
      // Child — start its own loop + server (never returns).
      serve($host, $port);
      exit(0);
   }

   if ($pid > 0) {
      $Pids[] = $pid;
   }
}

// @ Parent — reap children. If one dies, the container (the daemon) should go
//   down with it, so exit once any child is gone.
while (($reaped = pcntl_wait($status)) > 0) {
   exit(0);
}
exit(0);

/**
 * Run ONE worker: its own React loop + PgAsync client, bound with SO_REUSEPORT.
 */
function serve (string $host, int $port): void
{
   // ! Per-worker PgAsync client — created AFTER fork so each child owns its
   //   own connection bound to its own (global) React loop.
   $pass = getenv('DB_PASS');
   // ? Cap connections per worker to DB_POOL_MAX (default 1) — parity with the
   //   AMPHP PostgresConnectionPool and Bootgly's pool. Without this, PgAsync
   //   opens connections on demand and at high server-workers blows past PG's
   //   max_connections ("FATAL: sorry, too many clients already"), crashing the worker.
   $poolMax = (int) (getenv('DB_POOL_MAX') ?: 1);
   if ($poolMax < 1) {
      $poolMax = 1;
   }

   $Client = new Client([
      'host'            => getenv('DB_HOST') ?: '127.0.0.1',
      'port'            => getenv('DB_PORT') ?: '5432',
      'database'        => getenv('DB_NAME') ?: 'bootgly',
      'user'            => getenv('DB_USER') ?: 'postgres',
      'password'        => $pass === false ? '' : $pass,
      'auto_disconnect' => false,
      'max_connections' => $poolMax,
   ]);

   // ! Per-worker in-memory CachedWorld pool for /cached-queries (primed once,
   //   off the hot path). Filled when the priming query completes.
   $CachedWorlds = [];
   $Client
      ->query('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld')
      ->subscribe(
         static function (array $row) use (&$CachedWorlds): void {
            $id = (int) ($row['id'] ?? 0);
            $CachedWorlds[$id] = [
               'id'           => $id,
               'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
            ];
         }
      );

   // @ Async request handler — returns a Response or a Promise<Response>.
   $Server = new HttpServer(static function (ServerRequestInterface $Request) use ($Client, &$CachedWorlds): mixed {
      $headers = [];
      if (WorkerEvidence::$enabled) {
         $identity = WorkerEvidence::identify(
            $Request->getHeaderLine('X-Bootgly-Benchmark-Warmup'),
            $Request->getHeaderLine('X-Bootgly-Benchmark-Seal'),
         );
         if ($identity !== null) {
            $headers['X-Bootgly-Benchmark-Worker'] = $identity;
         }
      }

      $path = $Request->getUri()->getPath();

      // ? TechEmpower /plaintext + /json: static, no DB. Handle early.
      if ($path === '/plaintext') {
         return new Response(200, $headers + ['Content-Type' => 'text/plain'], 'Hello, World!');
      }
      if ($path === '/json') {
         return new Response(200, $headers + ['Content-Type' => 'application/json'], '{"message":"Hello, World!"}');
      }

      try {
         switch ($path) {
            case '/db':
               return fetchWorld($Client, mt_rand(1, 10000))->then(
                  static fn (array $World): Response => json(json_encode($World, JSON_NUMERIC_CHECK) ?: '{}', $headers)
               );

            case '/query':
               $queries = clamp(query_param($Request, 'queries'));
               $Promises = [];
               while ($queries-- > 0) {
                  $Promises[] = fetchWorld($Client, mt_rand(1, 10000));
               }

               return all($Promises)->then(
                  static fn (array $Worlds): Response => json(json_encode(array_values($Worlds), JSON_NUMERIC_CHECK) ?: '[]', $headers)
               );

            case '/fortunes':
               return $Client
                  ->query('SELECT id, message FROM Fortune')
                  ->toArray()
                  ->toPromise()
                  ->then(static function (array $rows) use ($headers): Response {
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

                     $body = "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$html}</table></body></html>";

                     return new Response(200, $headers + ['Content-Type' => 'text/html; charset=utf-8'], $body);
                  });

            case '/updates':
               $queries = clamp(query_param($Request, 'queries'));
               $Promises = [];
               while ($queries-- > 0) {
                  $Promises[] = fetchWorld($Client, mt_rand(1, 10000))->then(static function (array $World): array {
                     $World['randomNumber'] = mt_rand(1, 10000);

                     return $World;
                  });
               }

               return all($Promises)->then(static function (array $Worlds) use ($Client, $headers): PromiseInterface {
                  $Worlds = array_values($Worlds);

                  // @ ONE batched write: UPDATE World SET randomNumber =
                  //   CASE id WHEN $n::integer THEN $n::integer ... END
                  //   WHERE id IN ($n::integer, ...).
                  if ($Worlds === []) {
                     return resolve(json('[]', $headers));
                  }

                  $cases = [];
                  $ids = [];
                  $parameters = [];
                  $position = 1;
                  foreach ($Worlds as $World) {
                     $cases[] = 'WHEN $' . $position++ . '::integer THEN $' . $position++ . '::integer';
                     $parameters[] = $World['id'];
                     $parameters[] = $World['randomNumber'];
                  }
                  foreach ($Worlds as $World) {
                     $ids[] = '$' . $position++ . '::integer';
                     $parameters[] = $World['id'];
                  }

                  $sql = 'UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')';

                  return $Client
                     ->executeStatement($sql, $parameters)
                     ->toArray()
                     ->toPromise()
                     ->then(static fn (): Response => json(json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]', $headers));
               });

            case '/cached-queries':
               $count = clamp(query_param($Request, 'count'));
               $max = count($CachedWorlds);
               if ($max === 0) {
                  return json('[]', $headers);
               }
               $Worlds = [];
               while ($count-- > 0) {
                  $Worlds[] = $CachedWorlds[mt_rand(1, $max)] ?? null;
               }

               return json(json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]', $headers);

            case '/':
               // Warmup/probe — the runner warms up with GET /.
               return new Response(200, $headers + ['Content-Type' => 'text/plain'], 'TechEmpower Benchmark');

            default:
               return new Response(404, $headers + ['Content-Type' => 'text/plain'], 'Not Found');
         }
      }
      catch (Throwable $Throwable) {
         return new Response(500, $headers + ['Content-Type' => 'text/plain'], $Throwable->getMessage());
      }
   });

   // @ Bind with SO_REUSEPORT so every worker accepts on the same port and the
   //   kernel balances connections across them.
   $Socket = new SocketServer("{$host}:{$port}", [
      'tcp' => [
         'so_reuseport' => true,
      ],
   ]);

   $Server->listen($Socket);

   Loop::get()->run();
}

/**
 * Single World read: SELECT id, randomNumber FROM World WHERE id = $1.
 * Returns a promise resolving to ['id' => int, 'randomNumber' => int].
 */
function fetchWorld (Client $Client, int $id): PromiseInterface
{
   return $Client
      ->executeStatement('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1', [$id])
      ->toArray()
      ->toPromise()
      ->then(static function (array $rows): array {
         $row = $rows[0] ?? [];

         return [
            'id'           => (int) ($row['id'] ?? 0),
            'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
         ];
      });
}

/**
 * Clamp helper: TFB queries/count param, max(1, min(500, (int) $value)),
 * missing/invalid => 1.
 */
function clamp (mixed $value): int
{
   if (is_array($value)) {
      $value = $value[0] ?? 1;
   }

   return max(1, min(500, (int) $value));
}

/**
 * Read a query-string parameter ('queries' / 'count') from the PSR-7 request.
 */
function query_param (ServerRequestInterface $Request, string $name): mixed
{
   return $Request->getQueryParams()[$name] ?? 1;
}

/**
 * application/json response shortcut.
 */
function json (string $body, array $headers): Response
{
   return new Response(200, $headers + ['Content-Type' => 'application/json'], $body);
}
