<?php
/**
 * RoadRunner HTTP Worker — TechEmpower Routes
 *
 * Same route contract as the Swoole TechEmpower benchmark
 * (swoole-techempower-postgres.php): /plaintext, /json, /db, /query,
 * /fortunes, /updates, /cached-queries — backed by raw PDO (pdo_pgsql).
 *
 * The RoadRunner worker is long-lived and handles one request at a time, so a
 * single persistent PDO is opened ONCE before the waitRequest loop and reused
 * for the worker lifetime (no per-request connect, no pool). The CachedWorld
 * pool is primed once before the loop for the /cached-queries hot path.
 *
 * Usage: ./rr serve -c .rr.yaml -o "server.command=php worker-techempower.php"
 * (This worker is managed by the RoadRunner Go binary via goridge pipes)
 */

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;

if (extension_loaded('pdo_pgsql') === false) {
   fwrite(STDERR, "RoadRunner TechEmpower opponent requires the pdo_pgsql extension.\n");
   exit(1);
}

function env (string $name, string $default): string
{
   $value = getenv($name);

   return $value === false || $value === '' ? $default : $value;
}

// One persistent PDO per worker — created BEFORE the waitRequest loop.
$dbPort = env('DB_PORT', '5432');
$dsn = sprintf(
   'pgsql:host=%s;port=%d;dbname=%s',
   env('DB_HOST', '127.0.0.1'),
   is_numeric($dbPort) ? (int) $dbPort : 5432,
   env('DB_NAME', 'bootgly')
);
$pdo = new PDO($dsn, env('DB_USER', 'postgres'), env('DB_PASS', ''), [
   PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_EMULATE_PREPARES => false,
   PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Prepared statement reused across requests for single World reads.
$worldStatement = $pdo->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');

// Prime the per-worker in-memory CachedWorld pool for /cached-queries (NO DB on hot path).
$cachedWorlds = [];
foreach ($pdo->query('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld') as $row) {
   $id = (int) ($row['id'] ?? 0);
   $cachedWorlds[$id] = [
      'id' => $id,
      'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
   ];
}
$cacheCount = count($cachedWorlds);

// Map a World row to {"id":<int>,"randomNumber":<int>} (tolerate lowercase key + cast int).
$fetchWorld = static function (PDOStatement $Statement, int $id): array {
   $Statement->execute([$id]);
   $row = $Statement->fetch(PDO::FETCH_ASSOC) ?: [];

   return [
      'id' => (int) ($row['id'] ?? 0),
      'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
   ];
};

// Clamp a queries/count query param: missing/invalid => 1, else max(1, min(500, (int) value)).
$clamp = static function (array $query, string $name): int {
   $value = $query[$name] ?? 1;

   if (is_array($value)) {
      $value = $value[0] ?? 1;
   }

   return max(1, min(500, (int) $value));
};

$worker  = Worker::create();
$factory = new Psr17Factory();
$psr7    = new PSR7Worker($worker, $factory, $factory, $factory);

while ($request = $psr7->waitRequest()) {
   try {
      $path  = $request->getUri()->getPath();
      $query = $request->getQueryParams();

      // /plaintext + /json: static, no DB. Handle early.
      if ($path === '/plaintext') {
         $psr7->respond(new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!'));
         continue;
      }
      if ($path === '/json') {
         $psr7->respond(new Response(200, ['Content-Type' => 'application/json'], '{"message":"Hello, World!"}'));
         continue;
      }

      switch ($path) {
         case '/db':
            $World = $fetchWorld($worldStatement, mt_rand(1, 10000));
            $body = json_encode($World, JSON_NUMERIC_CHECK) ?: '{}';
            $psr7->respond(new Response(200, ['Content-Type' => 'application/json'], $body));
            continue 2;

         case '/query':
            $queries = $clamp($query, 'queries');
            $Worlds = [];
            while ($queries-- > 0) {
               $Worlds[] = $fetchWorld($worldStatement, mt_rand(1, 10000));
            }
            $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
            $psr7->respond(new Response(200, ['Content-Type' => 'application/json'], $body));
            continue 2;

         case '/fortunes':
            $Statement = $pdo->prepare('SELECT id, message FROM Fortune');
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

            $body = "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$html}</table></body></html>";
            $psr7->respond(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $body));
            continue 2;

         case '/updates':
            $queries = $clamp($query, 'queries');
            $Worlds = [];
            while ($queries-- > 0) {
               $World = $fetchWorld($worldStatement, mt_rand(1, 10000));
               $World['randomNumber'] = mt_rand(1, 10000);
               $Worlds[] = $World;
            }

            if ($Worlds !== []) {
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

               $Statement = $pdo->prepare('UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')');
               $Statement->execute($parameters);
            }

            $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
            $psr7->respond(new Response(200, ['Content-Type' => 'application/json'], $body));
            continue 2;

         case '/cached-queries':
            $count = $clamp($query, 'count');
            $Worlds = [];

            if ($cacheCount > 0) {
               while ($count-- > 0) {
                  $Worlds[] = $cachedWorlds[mt_rand(1, $cacheCount)] ?? null;
               }
            }

            $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
            $psr7->respond(new Response(200, ['Content-Type' => 'application/json'], $body));
            continue 2;

         case '/':
            // Warmup/probe: the runner warms up with GET /.
            $psr7->respond(new Response(200, ['Content-Type' => 'text/plain'], 'TechEmpower Benchmark'));
            continue 2;

         default:
            $psr7->respond(new Response(404, ['Content-Type' => 'text/plain'], 'Not Found'));
            continue 2;
      }
   } catch (\Throwable $e) {
      $psr7->respond(new Response(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'));
   }
}
