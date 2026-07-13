<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI async ADI Database probe
 * --------------------------------------------------------------------------
 * Starts one Bootgly HTTP_Server_CLI worker, generates load with Bootgly's
 * TCP_Client_CLI benchmark worker, and verifies that a deferred PostgreSQL
 * query does not block another HTTP response in the same worker.
 * --------------------------------------------------------------------------
 */

namespace BootglyBenchmarks\HTTP_Server_CLI\Probes;


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_BASE;
use const BOOTGLY_WORKING_DIR;
use const DIRECTORY_SEPARATOR;
use const GET;
use const PHP_BINARY;
use const PHP_EOL;
use const STDOUT;
use function ceil;
use function define;
use function defined;
use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function fwrite;
use function getenv;
use function is_file;
use function is_numeric;
use function is_resource;
use function json_encode;
use function microtime;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function putenv;
use function shell_exec;
use function spl_autoload_register;
use function sprintf;
use function str_contains;
use function str_replace;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function strlen;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;
use RuntimeException;
use Throwable;

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config as SQLConfig;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


$BenchmarksRoot = dirname(__DIR__, 2);
$BootglyRoot = probe_bootgly_root();
probe_bootstrap($BootglyRoot);

if (($argv[1] ?? '') === '--server') {
   probe_server($BootglyRoot);

   return;
}

exit(probe($BenchmarksRoot, $BootglyRoot) ? 0 : 1);

/**
 * Resolve the sibling bootgly checkout.
 */
function probe_bootgly_root (): string
{
   $Root = dirname(__DIR__, 3) . '/bootgly';

   if (file_exists($Root . '/Bootgly.php')) {
      return $Root;
   }

   $fallback = getenv('BOOTGLY_ROOT');

   if ($fallback !== false && file_exists($fallback . '/Bootgly.php')) {
      return $fallback;
   }

   throw new RuntimeException('Bootgly checkout not found. Set BOOTGLY_ROOT=/path/to/bootgly.');
}

/**
 * Load Bootgly classes without booting the interactive CLI command runner.
 */
function probe_bootstrap (string $Root): void
{
   if (defined('BOOTGLY_ROOT_BASE') === false) {
      define('BOOTGLY_ROOT_BASE', $Root);
      define('BOOTGLY_ROOT_DIR', $Root . DIRECTORY_SEPARATOR);
   }

   if (defined('BOOTGLY_WORKING_BASE') === false) {
      define('BOOTGLY_WORKING_BASE', BOOTGLY_ROOT_BASE);
      define('BOOTGLY_WORKING_DIR', BOOTGLY_ROOT_DIR);
   }

   if (defined('BOOTGLY_STORAGE_BASE') === false) {
      define('BOOTGLY_STORAGE_BASE', BOOTGLY_WORKING_DIR . 'storage');
      define('BOOTGLY_STORAGE_DIR', BOOTGLY_STORAGE_BASE . DIRECTORY_SEPARATOR);
   }

   if (defined('BOOTGLY_VERSION') === false) {
      define('BOOTGLY_VERSION', '0.16.0-beta');
   }

   @include $Root . '/vendor/autoload.php';

   spl_autoload_register(static function (string $class): void {
      $path = str_replace('\\', '/', $class) . '.php';
      $file = BOOTGLY_WORKING_DIR . $path;

      if (is_file($file) === false && BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $file = BOOTGLY_ROOT_DIR . $path;
      }

      if (is_file($file) === false) {
         return;
      }

      include $file;
   });

   if (defined('Bootgly\\CLI') === false) {
      if (defined('GET') === false) {
         require $Root . '/Bootgly/WPI/autoboot.php';
      }

      $CLI = new \stdClass;
      $CLI->Commands = new \Bootgly\CLI\Commands;
      $CLI->Terminal = new \Bootgly\CLI\Terminal;
      $WPI = new \Bootgly\WPI;
      define('Bootgly\\CLI', $CLI);
      define('Bootgly\\WPI', $WPI);

      return;
   }

   if (defined('GET') === false) {
      require $Root . '/Bootgly/WPI/autoboot.php';
   }
}

/**
 * Run the async database benchmark probe controller.
 */
function probe (string $BenchmarksRoot, string $BootglyRoot): bool
{
   $port = (int) probe_env('BOOTGLY_ADI_PROBE_PORT', '18082');
   $connections = (int) probe_env('BOOTGLY_ADI_PROBE_CONNECTIONS', '64');
   $clientWorkers = (int) probe_env('BOOTGLY_ADI_PROBE_CLIENT_WORKERS', '2');
   $duration = (int) probe_env('BOOTGLY_ADI_PROBE_DURATION', '12');
   $pipeline = (int) probe_env('BOOTGLY_ADI_PROBE_PIPELINE', '1');
   $dbSleep = (float) probe_env('BOOTGLY_ADI_PROBE_DB_SLEEP', '2');
   $fastMax = (float) probe_env('BOOTGLY_ADI_PROBE_FAST_MAX', '0.5');
   $psql = probe_command('psql');

   if ($psql === '') {
      probe_line('FAIL: psql not found in PATH.');

      return false;
   }

   probe_defaults();

   if (probe_psql($psql, 'SELECT 1') !== '1') {
      probe_line('FAIL: PostgreSQL is not reachable with DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS.');

      return false;
   }

   $tcpWorker = $BenchmarksRoot . '/runners/TCP_Client/worker.php';

   if (file_exists($tcpWorker) === false) {
      probe_line('FAIL: Bootgly TCP_Client_CLI benchmark worker not found.');

      return false;
   }

   $serverLog = sys_get_temp_dir() . '/bootgly-adi-async-database-probe-server.log';
   $loadLog = sys_get_temp_dir() . '/bootgly-adi-async-database-probe-load.log';
   $loadFile = probe_build_load();
   $Server = probe_server_start($BootglyRoot, $serverLog);
   $Load = null;

   if ($Server === null) {
      @unlink($loadFile);

      return false;
   }

   try {
      if (probe_ready($Server, $port) === false) {
         probe_line('FAIL: probe HTTP server did not become ready.');
         probe_line((string) file_get_contents($serverLog));

         return false;
      }

      $Load = probe_load_start($tcpWorker, $port, $connections, $duration, $clientWorkers, $pipeline, $loadFile, $loadLog);

      if ($Load === null) {
         probe_line('FAIL: could not start Bootgly TCP_Client_CLI load worker.');

         return false;
      }

      $dbStarted = microtime(true);
      $dbSocket = probe_open($port, '/db-sleep');
      $seen = false;
      $active = '0';

      for ($attempt = 0; $attempt < 300; $attempt++) {
         $active = probe_psql($psql, "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND state = 'active' AND query LIKE 'SELECT pg_sleep%'");

         if ($active !== '0' && $active !== '') {
            $seen = true;
            break;
         }

         usleep(10_000);
      }

      $fast = probe_request($port, '/fast');
      $db = probe_read($dbSocket, $dbSleep + 10.0);
      $dbTime = microtime(true) - $dbStarted;
      $loadCode = proc_close($Load);
      $Load = null;
      $loadOutput = trim((string) file_get_contents($loadLog));
      $passed = $seen
         && $active !== '0'
         && $db['code'] === 200
         && $fast['code'] === 200
         && $fast['time'] <= $fastMax
         && str_contains($db['body'], '"value":42')
         && $loadCode === 0
         && $loadOutput !== '';

      probe_line('Bootgly HTTP_Server_CLI async ADI Database probe');
      probe_line('--------------------------------------------------');
      probe_line('HTTP server workers: 1');
      probe_line("Load generator: Bootgly TCP_Client_CLI connections={$connections} workers={$clientWorkers} duration={$duration}s pipeline={$pipeline}");
      probe_line('PostgreSQL active pg_sleep seen: ' . ($seen ? 'yes' : 'no') . " (active={$active})");
      probe_line('DB response: HTTP ' . $db['code'] . ' in ' . sprintf('%.6f', $dbTime) . 's');
      probe_line('Fast response during active DB: HTTP ' . $fast['code'] . ' in ' . sprintf('%.6f', $fast['time']) . 's');
      probe_line('DB body: ' . $db['body']);
      probe_line('Load output: ' . $loadOutput);
      probe_line('');
      probe_line($passed ? 'PASS: database wait did not block the single HTTP worker.' : 'FAIL: async database probe did not meet the expected thresholds.');

      return $passed;
   }
   catch (Throwable $Throwable) {
      probe_line('FAIL: ' . $Throwable->getMessage());

      return false;
   }
   finally {
      if (is_resource($Load)) {
         $status = proc_get_status($Load);

         if (($status['running'] ?? false) === true) {
            proc_terminate($Load);
         }

         proc_close($Load);
      }

      probe_server_stop($Server);
      @unlink($loadFile);
   }
}

/**
 * Build a temporary TCP_Client_CLI load that keeps load on /load.
 */
function probe_build_load (): string
{
   $file = sys_get_temp_dir() . '/bootgly-adi-async-database-probe-load-' . microtime(true) . '.json';
   file_put_contents($file, json_encode([
      'method' => 'GET',
      'paths' => ['/load'],
   ]));

   return $file;
}

/**
 * Start the one-worker HTTP server used by the probe.
 *
 * @return resource|null
 */
function probe_server_start (string $Root, string $log): mixed
{
   $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --server';
   $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['file', $log, 'w'],
      2 => ['file', $log, 'a'],
   ];
   $Process = proc_open($command, $descriptors, $pipes, $Root);

   if (is_resource($Process) === false) {
      return null;
   }

   if (isset($pipes[0]) && is_resource($pipes[0])) {
      fclose($pipes[0]);
   }

   return $Process;
}

/**
 * Stop a probe HTTP server process.
 *
 * @param resource $Process
 */
function probe_server_stop (mixed $Process): void
{
   $status = proc_get_status($Process);
   $pid = (int) ($status['pid'] ?? 0);

   if ($pid > 0) {
      shell_exec('pkill -TERM -P ' . escapeshellarg((string) $pid) . ' 2>/dev/null || true');
   }

   if (($status['running'] ?? false) === true) {
      proc_terminate($Process);
   }

   usleep(100_000);
   $status = proc_get_status($Process);

   if (($status['running'] ?? false) === true) {
      proc_terminate($Process, 9);
   }

   if ($pid > 0) {
      shell_exec('pkill -KILL -P ' . escapeshellarg((string) $pid) . ' 2>/dev/null || true');
   }

   proc_close($Process);
}

/**
 * Wait until the probe server responds to /fast.
 *
 * @param resource $Process
 */
function probe_ready (mixed $Process, int $port): bool
{
   for ($attempt = 0; $attempt < 200; $attempt++) {
      $status = proc_get_status($Process);

      if (($status['running'] ?? false) !== true) {
         return false;
      }

      try {
         $response = probe_request($port, '/fast', 0.1);

         if ($response['code'] === 200) {
            return true;
         }
      }
      catch (Throwable) {
         // wait until the socket is accepting connections
      }

      usleep(25_000);
   }

   return false;
}

/**
 * Start Bootgly TCP_Client_CLI load against the probe /load route.
 *
 * @return resource|null
 */
function probe_load_start (
   string $worker,
   int $port,
   int $connections,
   int $duration,
   int $workers,
   int $pipeline,
   string $loadFile,
   string $log
): mixed
{
   $command = escapeshellarg(PHP_BINARY)
      . ' ' . escapeshellarg($worker)
      . ' --host=127.0.0.1'
      . ' --port=' . $port
      . ' --connections=' . $connections
      . ' --duration=' . $duration
      . ' --paths-file=' . escapeshellarg($loadFile)
      . ' --workers=' . $workers;

   if ($pipeline > 1) {
      $command .= ' --pipeline=' . $pipeline;
   }

   $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['file', $log, 'w'],
      2 => ['file', $log, 'a'],
   ];
   $Process = proc_open($command, $descriptors, $pipes);

   if (is_resource($Process) === false) {
      return null;
   }

   if (isset($pipes[0]) && is_resource($pipes[0])) {
      fclose($pipes[0]);
   }

   return $Process;
}

/**
 * Open an HTTP request and leave the socket non-blocking for later reads.
 *
 * @return resource
 */
function probe_open (int $port, string $path): mixed
{
   $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 3.0);

   if (is_resource($socket) === false) {
      throw new RuntimeException("HTTP connect failed: {$error}");
   }

   fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
   stream_set_blocking($socket, false);

   return $socket;
}

/**
 * Send one blocking HTTP request.
 *
 * @return array{code:int,time:float,body:string,raw:string}
 */
function probe_request (int $port, string $path, float $timeout = 3.0): array
{
   $started = microtime(true);
   $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, $timeout);

   if (is_resource($socket) === false) {
      throw new RuntimeException("HTTP connect failed: {$error}");
   }

   fwrite($socket, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
   $raw = '';

   while (feof($socket) === false) {
      $chunk = fread($socket, 8192);

      if ($chunk === false || $chunk === '') {
         continue;
      }

      $raw .= $chunk;

      if (probe_complete($raw)) {
         break;
      }
   }

   fclose($socket);
   $parsed = probe_parse($raw);
   $parsed['time'] = microtime(true) - $started;

   return $parsed;
}

/**
 * Read a pending non-blocking HTTP response.
 *
 * @param resource $socket
 * @return array{code:int,time:float,body:string,raw:string}
 */
function probe_read (mixed $socket, float $timeout): array
{
   $started = microtime(true);
   $raw = '';

   while (feof($socket) === false) {
      $elapsed = microtime(true) - $started;
      $remaining = $timeout - $elapsed;

      if ($remaining <= 0) {
         throw new RuntimeException('HTTP read timed out.');
      }

      $reads = [$socket];
      $writes = [];
      $excepts = [];
      $seconds = (int) $remaining;
      $microseconds = (int) ceil(($remaining - $seconds) * 1_000_000);
      $selected = stream_select($reads, $writes, $excepts, $seconds, $microseconds);

      if ($selected === false) {
         throw new RuntimeException('HTTP stream_select failed.');
      }

      if ($selected === 0) {
         continue;
      }

      $chunk = fread($socket, 8192);

      if ($chunk === false || $chunk === '') {
         continue;
      }

      $raw .= $chunk;

      if (probe_complete($raw)) {
         break;
      }
   }

   fclose($socket);
   $parsed = probe_parse($raw);
   $parsed['time'] = microtime(true) - $started;

   return $parsed;
}

/**
 * Parse an HTTP response.
 *
 * @return array{code:int,time:float,body:string,raw:string}
 */
function probe_parse (string $raw): array
{
   $parts = explode("\r\n\r\n", $raw, 2);
   $head = $parts[0] ?? '';
   $body = $parts[1] ?? '';
   $code = 0;

   if (preg_match('/^HTTP\/\S+\s+(\d{3})/m', $head, $matches) === 1) {
      $code = (int) $matches[1];
   }

   return [
      'code' => $code,
      'time' => 0.0,
      'body' => $body,
      'raw' => $raw,
   ];
}

/**
 * Check if a raw HTTP response has the full declared body.
 */
function probe_complete (string $raw): bool
{
   $parts = explode("\r\n\r\n", $raw, 2);

   if (isset($parts[1]) === false) {
      return false;
   }

   if (preg_match('/\r\nContent-Length:\s*(\d+)\r\n/i', "\r\n" . $parts[0] . "\r\n", $matches) !== 1) {
      return false;
   }

   return strlen($parts[1]) >= (int) $matches[1];
}

/**
 * Execute a scalar psql query and return stdout.
 */
function probe_psql (string $psql, string $sql): string
{
   $command = 'PGPASSWORD=' . escapeshellarg(probe_env('DB_PASS', '')) . ' '
      . escapeshellarg($psql)
      . ' -h ' . escapeshellarg(probe_env('DB_HOST', '127.0.0.1'))
      . ' -p ' . escapeshellarg(probe_env('DB_PORT', '5432'))
      . ' -U ' . escapeshellarg(probe_env('DB_USER', 'postgres'))
      . ' -d ' . escapeshellarg(probe_env('DB_NAME', 'bootgly'))
      . ' -At -c ' . escapeshellarg($sql)
      . ' 2>/dev/null';

   return trim((string) shell_exec($command));
}

/**
 * Ensure the server child sees a database-enabled config scope.
 */
function probe_defaults (): void
{
   $defaults = [
      'DB_ENABLED' => 'true',
      'DB_CONNECTION' => 'pgsql',
      'DB_HOST' => '127.0.0.1',
      'DB_PORT' => '5432',
      'DB_NAME' => 'bootgly',
      'DB_USER' => 'postgres',
      'DB_PASS' => '',
      'DB_POOL_MIN' => '0',
      'DB_POOL_MAX' => '8',
      'DB_STATEMENTS' => '256',
      'DB_TIMEOUT' => '30',
      'DB_SSLMODE' => 'disable',
      'DB_SSLVERIFY' => 'false',
      'DB_SSLPEER' => '',
      'DB_SSLCAFILE' => '',
   ];

   foreach ($defaults as $key => $value) {
      if (getenv($key) === false) {
         putenv("{$key}={$value}");
      }
   }
}

/**
 * Build the ADI SQL config used by the probe server.
 */
function probe_sql_config (): SQLConfig
{
   $port = probe_env('DB_PORT', (string) SQLConfig::DEFAULT_PORT);
   $timeout = probe_env('DB_TIMEOUT', (string) SQLConfig::DEFAULT_TIMEOUT);
   $poolMin = probe_env('DB_POOL_MIN', (string) SQLConfig::DEFAULT_POOL_MIN);
   $poolMax = probe_env('DB_POOL_MAX', (string) SQLConfig::DEFAULT_POOL_MAX);
   $statements = probe_env('DB_STATEMENTS', (string) SQLConfig::DEFAULT_STATEMENTS);

   return new SQLConfig([
      'driver' => probe_env('DB_CONNECTION', SQLConfig::DEFAULT_DRIVER),
      'host' => probe_env('DB_HOST', SQLConfig::DEFAULT_HOST),
      'port' => is_numeric($port) ? (int) $port : SQLConfig::DEFAULT_PORT,
      'database' => probe_env('DB_NAME', SQLConfig::DEFAULT_DATABASE),
      'username' => probe_env('DB_USER', SQLConfig::DEFAULT_USERNAME),
      'password' => probe_env('DB_PASS', SQLConfig::DEFAULT_PASSWORD),
      'timeout' => is_numeric($timeout) ? (float) $timeout : SQLConfig::DEFAULT_TIMEOUT,
      'statements' => is_numeric($statements) ? (int) $statements : SQLConfig::DEFAULT_STATEMENTS,
      'pool' => [
         'min' => is_numeric($poolMin) ? (int) $poolMin : SQLConfig::DEFAULT_POOL_MIN,
         'max' => is_numeric($poolMax) ? (int) $poolMax : SQLConfig::DEFAULT_POOL_MAX,
      ],
      'secure' => [
         'mode' => probe_env('DB_SSLMODE', SQLConfig::SECURE_DISABLE),
         'verify' => probe_bool('DB_SSLVERIFY', false),
         'peer' => probe_env('DB_SSLPEER', ''),
         'cafile' => probe_env('DB_SSLCAFILE', ''),
      ],
   ]);
}

/**
 * Read a bool-like environment variable.
 */
function probe_bool (string $key, bool $default): bool
{
   $value = strtolower(probe_env($key, $default ? 'true' : 'false'));

   return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
}

/**
 * Get one environment variable with a default.
 */
function probe_env (string $key, string $default): string
{
   $value = getenv($key);

   if ($value === false || $value === '') {
      return $default;
   }

   return $value;
}

/**
 * Resolve one required CLI command.
 */
function probe_command (string $binary): string
{
   return trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
}

/**
 * Print one probe line.
 */
function probe_line (string $line): void
{
   fwrite(STDOUT, $line . PHP_EOL);
}

/**
 * Run the one-worker HTTP server used by the probe.
 */
function probe_server (string $Root): void
{
   $port = (int) probe_env('BOOTGLY_ADI_PROBE_PORT', '18082');
   $dbSleep = (float) probe_env('BOOTGLY_ADI_PROBE_DB_SLEEP', '2');
   $loadDelay = (float) probe_env('BOOTGLY_ADI_PROBE_LOAD_DELAY', '0.25');
   $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: Modes::Interactive);
   $HTTP_Server_CLI->configure(
      host: '127.0.0.1',
      port: $port,
      workers: 1,
      responseResources: [
         'Database' => static function (object $Context): DatabaseResource {
            static $Database = null;

            if ($Context instanceof Response === false) {
               throw new RuntimeException('Database response resource expects a Response context.');
            }

            if ($Database instanceof SQL === false) {
               $Database = new SQL(probe_sql_config());
            }

            return new DatabaseResource($Database);
         },
      ],
   );

   $HTTP_Server_CLI->on(
      Events::RequestReceived,
      static function (Request $Request, Response $Response, Router $Router) use ($dbSleep, $loadDelay) {
         yield $Router->route('/fast', function (Request $Request, Response $Response) {
            return $Response(body: 'FAST');
         }, GET);

         yield $Router->route('/load', function (Request $Request, Response $Response) use ($loadDelay) {
            return $Response->defer(function (Response $Response) use ($loadDelay): void {
               $started = microtime(true);

               while (microtime(true) - $started < $loadDelay) {
                  $Response->wait();
               }

               $Response(body: 'LOAD');
            });
         }, GET);

         yield $Router->route('/db-sleep', function (Request $Request, Response $Response) use ($dbSleep) {
            return $Response->defer(function (Response $Response) use ($dbSleep): void {
               try {
                  if (probe_bool('DB_ENABLED', true) === false) {
                     $Response(code: 500, body: 'database config disabled');

                     return;
                  }

                  $Database = $Response->Database;
                  $Result = $Database->fetch('SELECT pg_sleep($1::float8), $2::int AS value', [$dbSleep, 42]);

                  $Response(body: json_encode($Result->rows) ?: 'json error');
               }
               catch (Throwable $Throwable) {
                  $Response(code: 500, body: $Throwable->getMessage());
               }
            });
         }, GET);

         yield $Router->route('/*', function (Request $Request, Response $Response) {
            return $Response(code: 404, body: 'NOT FOUND');
         });
      },
   );

   $HTTP_Server_CLI->start();
}
