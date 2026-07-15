<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootables/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;


if (extension_loaded('pdo_pgsql') === false) {
   fwrite(STDERR, "FrankenPHP TechEmpower opponent requires the pdo_pgsql extension.\n");
   exit(1);
}

$Read = static function (string $name, string $default): string {
   $value = getenv($name);

   return $value === false || $value === '' ? $default : $value;
};

$DBHost = $Read('DB_HOST', '127.0.0.1');
$DBPort = $Read('DB_PORT', '5432');
$DBName = $Read('DB_NAME', 'bootgly');
$DBUser = $Read('DB_USER', 'postgres');
$DBPass = $Read('DB_PASS', '');
$DSN = "pgsql:host={$DBHost};port={$DBPort};dbname={$DBName}";

$PDO = new PDO($DSN, $DBUser, $DBPass, [
   PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
   PDO::ATTR_EMULATE_PREPARES => false,
   PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$WorldStatement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');

$CachedWorlds = [];
$CacheStatement = $PDO->prepare('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld');
$CacheStatement->execute();
foreach ($CacheStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
   $ID = (int) ($row['id'] ?? 0);
   $CachedWorlds[$ID] = [
      'id' => $ID,
      'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
   ];
}
$cacheCount = count($CachedWorlds);

$Fetch = static function (int $ID) use ($WorldStatement): array {
   $WorldStatement->execute([$ID]);
   $row = $WorldStatement->fetch(PDO::FETCH_ASSOC) ?: [];

   return [
      'id' => (int) ($row['id'] ?? 0),
      'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
   ];
};

$Clamp = static function (string $URL, string $name): int {
   $value = 1;
   $queryPosition = strpos($URL, '?');

   if ($queryPosition !== false) {
      parse_str(substr($URL, $queryPosition + 1), $parameters);
      if (isset($parameters[$name])) {
         $value = is_array($parameters[$name])
            ? ($parameters[$name][0] ?? 1)
            : $parameters[$name];
      }
   }

   return max(1, min(500, (int) $value));
};

$Update = static function (array $Worlds) use ($PDO): void {
   if ($Worlds === []) {
      return;
   }

   $cases = [];
   $IDs = [];
   $parameters = [];

   foreach ($Worlds as $World) {
      $cases[] = 'WHEN ?::integer THEN ?::integer';
      $parameters[] = $World['id'];
      $parameters[] = $World['randomNumber'];
   }

   foreach ($Worlds as $World) {
      $IDs[] = '?::integer';
      $parameters[] = $World['id'];
   }

   $Statement = $PDO->prepare(
      'UPDATE World SET randomNumber = CASE id '
      . implode(' ', $cases)
      . ' END WHERE id IN ('
      . implode(',', $IDs)
      . ')'
   );
   $Statement->execute($parameters);
};

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

WorkerEvidence::boot();

for ($requestNumber = 0; !$maxRequests || $requestNumber < $maxRequests; $requestNumber++) {
   $keepRunning = \frankenphp_handle_request(static function () use (
      $PDO,
      $Fetch,
      $Clamp,
      $Update,
      &$CachedWorlds,
      $cacheCount,
   ): void {
      if (WorkerEvidence::$enabled) {
         $marker = $_SERVER['HTTP_X_BOOTGLY_BENCHMARK_WARMUP'] ?? null;
         $nonce = $_SERVER['HTTP_X_BOOTGLY_BENCHMARK_NONCE'] ?? null;
         $seal = $_SERVER['HTTP_X_BOOTGLY_BENCHMARK_SEAL'] ?? null;
         $identity = WorkerEvidence::identify(
            is_string($marker) ? $marker : null,
            is_string($nonce) ? $nonce : null,
            is_string($seal) ? $seal : null,
         );

         if ($identity !== null) {
            header("X-Bootgly-Benchmark-Worker: {$identity}");
         }
      }

      $URL = $_SERVER['REQUEST_URI'] ?? '/';
      $queryPosition = strpos($URL, '?');
      $path = $queryPosition === false ? $URL : substr($URL, 0, $queryPosition);

      if ($path === '/plaintext') {
         header('Content-Type: text/plain');
         echo 'Hello, World!';

         return;
      }
      if ($path === '/json') {
         header('Content-Type: application/json');
         echo '{"message":"Hello, World!"}';

         return;
      }

      try {
         switch ($path) {
            case '/db':
               $World = $Fetch(mt_rand(1, 10000));
               header('Content-Type: application/json');
               echo json_encode($World, JSON_NUMERIC_CHECK) ?: '{}';

               return;

            case '/query':
               $queries = $Clamp($URL, 'queries');
               $Worlds = [];
               while ($queries-- > 0) {
                  $Worlds[] = $Fetch(mt_rand(1, 10000));
               }
               header('Content-Type: application/json');
               echo json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';

               return;

            case '/updates':
               $queries = $Clamp($URL, 'queries');
               $Worlds = [];
               while ($queries-- > 0) {
                  $World = $Fetch(mt_rand(1, 10000));
                  $World['randomNumber'] = mt_rand(1, 10000);
                  $Worlds[] = $World;
               }
               $Update($Worlds);
               header('Content-Type: application/json');
               echo json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';

               return;

            case '/fortunes':
               $Statement = $PDO->prepare('SELECT id, message FROM Fortune');
               $Statement->execute();
               $rows = $Statement->fetchAll(PDO::FETCH_ASSOC);
               $Fortunes = [0 => 'Additional fortune added at request time.'];
               foreach ($rows as $row) {
                  $Fortunes[(int) $row['id']] = (string) $row['message'];
               }
               asort($Fortunes);

               $HTML = '';
               foreach ($Fortunes as $ID => $message) {
                  $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                  $HTML .= "<tr><td>{$ID}</td><td>{$message}</td></tr>";
               }
               header('Content-Type: text/html; charset=utf-8');
               echo "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$HTML}</table></body></html>";

               return;

            case '/cached-queries':
               $count = $Clamp($URL, 'count');
               header('Content-Type: application/json');
               if ($cacheCount === 0) {
                  echo '[]';

                  return;
               }

               $Worlds = [];
               while ($count-- > 0) {
                  $Worlds[] = $CachedWorlds[mt_rand(1, $cacheCount)] ?? null;
               }
               echo json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';

               return;

            case '/':
               header('Content-Type: text/plain');
               echo 'TechEmpower Benchmark';

               return;

            default:
               http_response_code(404);
               header('Content-Type: text/plain');
               echo 'Not Found';

               return;
         }
      }
      catch (Throwable $Throwable) {
         http_response_code(500);
         header('Content-Type: text/plain');
         echo $Throwable->getMessage();
      }
   });

   gc_collect_cycles();

   if (!$keepRunning) {
      break;
   }
}
