<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly Benchmarks — HTTP_Server_CLI — AMPHP TechEmpower bootable
 * --------------------------------------------------------------------------
 *
 * Amp v3 (fibers) async HTTP server serving the 7 TechEmpower (TFB) routes:
 *   /plaintext /json /db /query /fortunes /updates /cached-queries
 * (+ a compatibility GET / route; anything else => 404).
 *
 * Worker model (mirrors the manual pcntl_fork + SO_REUSEPORT idiom used by the
 * Swoole and ReactPHP opponents — NOT amphp/cluster): the
 * parent pcntl_fork()s $workers children; EACH child runs its OWN Revolt event
 * loop and binds 0.0.0.0:8082 with SO_REUSEPORT. The parent only reaps.
 *
 * DB is async via amphp/postgres (ext-pgsql, NOT PDO): one PostgresConnectionPool
 * per worker (size = DB_POOL_MAX, default 1 for parity). Fibers make the handler
 * read synchronous-style while staying non-blocking.
 *
 * amphp/postgres uses numbered placeholders ($1, $2, ...), NOT `?` — the SQL is
 * adapted accordingly while keeping byte-identical response bodies vs the
 * Workerman/Swoole references.
 *
 * Run (foreground — the container is the daemon):
 *   php amphp-techempower.php
 */

require_once 'vendor/autoload.php';
require_once dirname(__DIR__) . '/WorkerEvidence.php';

use function Amp\trapSignal;
use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Socket\BindContext;
use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Psr\Log\NullLogger;

if (extension_loaded('pgsql') === false) {
    fwrite(STDERR, "AMPHP TechEmpower opponent requires the pgsql extension (amphp/postgres).\n");
    exit(1);
}

$host = '0.0.0.0';
$port = 8082;

// # Worker count: BOOTGLY_WORKERS, else half the cores (min 1).
$workers = (int) (getenv('BOOTGLY_WORKERS') ?: max(1, (int) ((int) (shell_exec('nproc 2>/dev/null') ?: 1) / 2)));
$workers = max(1, $workers);

// @ Fork exactly $workers serving children. The parent remains a supervisor
//   and reaps them; it never consumes one of the configured worker slots.
$PIDs = [];
$serving = false;
for ($i = 0; $i < $workers; $i++) {
    $PID = pcntl_fork();
    if ($PID === 0) {
        $serving = true;
        break;
    }
    if ($PID < 0) {
        foreach ($PIDs as $ChildPID) {
            posix_kill($ChildPID, SIGTERM);
        }
        fwrite(STDERR, "AMPHP worker fork failed.\n");
        exit(1);
    }

    $PIDs[] = $PID;
}

if (!$serving) {
    $exit = 0;
    foreach ($PIDs as $ChildPID) {
        $waited = pcntl_waitpid($ChildPID, $status);
        if (
            $waited !== $ChildPID
            || !pcntl_wifexited($status)
            || pcntl_wexitstatus($status) !== 0
        ) {
            $exit = 1;
        }
    }
    exit($exit);
}

// ============================================================================
// Worker child: own event loop.
// ============================================================================

// # ONE persistent async PostgreSQL pool per worker.
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('DB_PORT') ?: '5432');
$dbName = getenv('DB_NAME') ?: 'bootgly';
$dbUser = getenv('DB_USER') ?: 'postgres';
$dbPass = getenv('DB_PASS');
$dbPass = $dbPass === false ? '' : $dbPass;

$poolMax = getenv('DB_POOL_MAX') ?: '1';
$poolSize = is_numeric($poolMax) ? max(1, (int) $poolMax) : 1;

$Config = new PostgresConfig(
    host: $dbHost,
    port: $dbPort,
    user: $dbUser,
    password: $dbPass,
    database: $dbName,
);
$Pool = new PostgresConnectionPool($Config, $poolSize);

// # Per-worker in-memory CachedWorld pool for /cached-queries (no DB on hot path).
//   Primed once at boot, inside the fiber, from CachedWorld.
$cachedWorlds = [];
foreach ($Pool->query('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld') as $row) {
    $id = (int) ($row['id'] ?? 0);
    $cachedWorlds[$id] = [
        'id' => $id,
        'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
    ];
}

// Clamp helper: TFB queries/count param, max(1, min(500, (int) $value)), missing/invalid => 1.
$clamp = static function ($value): int {
    if (is_array($value)) {
        $value = $value[0] ?? 1;
    }

    return max(1, min(500, (int) $value));
};

// Read a query-string param off the Amp request (string|null).
$param = static function (Request $Request, string $name) {
    $query = $Request->getUri()->getQuery();
    if ($query === '') {
        return null;
    }
    parse_str($query, $params);

    return $params[$name] ?? null;
};

// Single World read (one-shot pool execute; drain the Result fully so the pooled
// connection is released cleanly before the next query). A pool-prepared
// statement cannot be reused across executes here — amphp/postgres deallocates
// it when the connection cycles ("prepared statement does not exist") — so we
// execute per call instead.
$fetchWorld = static function ($Pool, int $id): array {
    $Result = $Pool->execute('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1', [$id]);
    $row = null;
    foreach ($Result as $r) {
        if ($row === null) {
            $row = $r;
        }
    }
    $row = $row ?: [];

    return [
        'id' => (int) ($row['id'] ?? 0),
        'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
    ];
};

$RequestHandler = new ClosureRequestHandler(
    static function (Request $Request) use (&$Pool, &$cachedWorlds, $clamp, $param, $fetchWorld): Response {
        $headers = [];
        if (WorkerEvidence::$enabled) {
            $identity = WorkerEvidence::identify(
                $Request->getHeader('X-Bootgly-Benchmark-Warmup'),
                $Request->getHeader('X-Bootgly-Benchmark-Seal'),
            );
            if ($identity !== null) {
                $headers['X-Bootgly-Benchmark-Worker'] = $identity;
            }
        }

        $path = $Request->getUri()->getPath();

        // @ TechEmpower /plaintext + /json: static, no DB. Handle early.
        if ($path === '/plaintext') {
            return new Response(HttpStatus::OK, $headers + ['content-type' => 'text/plain'], 'Hello, World!');
        }
        if ($path === '/json') {
            return new Response(HttpStatus::OK, $headers + ['content-type' => 'application/json'], '{"message":"Hello, World!"}');
        }

        try {
            $contentType = 'application/json';

            switch ($path) {
                case '/db':
                    $World = $fetchWorld($Pool, mt_rand(1, 10000));
                    $body = json_encode($World, JSON_NUMERIC_CHECK) ?: '{}';
                    break;

                case '/query':
                    $queries = $clamp($param($Request, 'queries'));
                    $Worlds = [];
                    while ($queries-- > 0) {
                        $Worlds[] = $fetchWorld($Pool, mt_rand(1, 10000));
                    }
                    $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
                    break;

                case '/fortunes':
                    $contentType = 'text/html; charset=utf-8';
                    $Result = $Pool->query('SELECT id, message FROM Fortune');
                    $Fortunes = [0 => 'Additional fortune added at request time.'];
                    foreach ($Result as $row) {
                        $Fortunes[(int) $row['id']] = (string) $row['message'];
                    }
                    asort($Fortunes);
                    $html = '';
                    foreach ($Fortunes as $id => $message) {
                        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                        $html .= "<tr><td>{$id}</td><td>{$message}</td></tr>";
                    }
                    $body = "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$html}</table></body></html>";
                    break;

                case '/updates':
                    $queries = $clamp($param($Request, 'queries'));
                    $Worlds = [];
                    while ($queries-- > 0) {
                        $World = $fetchWorld($Pool, mt_rand(1, 10000));
                        $World['randomNumber'] = mt_rand(1, 10000);
                        $Worlds[] = $World;
                    }

                    // ONE batched write: UPDATE World SET randomNumber = CASE id WHEN $n::integer THEN $n::integer END WHERE id IN ($n::integer, ...).
                    if ($Worlds !== []) {
                        $cases = [];
                        $ids = [];
                        $parameters = [];
                        $n = 1;
                        foreach ($Worlds as $World) {
                            $cases[] = 'WHEN $' . $n++ . '::integer THEN $' . $n++ . '::integer';
                            $parameters[] = $World['id'];
                            $parameters[] = $World['randomNumber'];
                        }
                        foreach ($Worlds as $World) {
                            $ids[] = '$' . $n++ . '::integer';
                            $parameters[] = $World['id'];
                        }
                        $Pool->execute('UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')', $parameters);
                    }

                    $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
                    break;

                case '/cached-queries':
                    $count = $clamp($param($Request, 'count'));
                    $max = count($cachedWorlds);
                    if ($max === 0) {
                        $body = '[]';
                        break;
                    }
                    $Worlds = [];
                    while ($count-- > 0) {
                        $Worlds[] = $cachedWorlds[mt_rand(1, $max)] ?? null;
                    }
                    $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
                    break;

                case '/':
                    // Warmup/probe — the runner warms up with GET /.
                    return new Response(HttpStatus::OK, $headers + ['content-type' => 'text/plain'], 'TechEmpower Benchmark');

                default:
                    return new Response(HttpStatus::NOT_FOUND, $headers + ['content-type' => 'text/plain'], 'Not Found');
            }

            return new Response(HttpStatus::OK, $headers + ['content-type' => $contentType], $body);
        }
        catch (Throwable $Throwable) {
            return new Response(HttpStatus::INTERNAL_SERVER_ERROR, $headers + ['content-type' => 'text/plain'], $Throwable->getMessage());
        }
    }
);

// # SO_REUSEPORT bind context so every forked worker can listen on :8082.
$BindContext = (new BindContext)->withReusePort();

// # Direct-access HTTP server (no proxy); NullLogger keeps the hot path quiet.
#   Compression OFF for TFB parity (Workerman/Swoole references disable it) and
#   so response bodies stay byte-identical and uncompressed.
$Logger = new NullLogger;
$Server = SocketHttpServer::createForDirectAccess($Logger, enableCompression: false);
$Server->expose("{$host}:{$port}", $BindContext);
$Server->start($RequestHandler, new DefaultErrorHandler);

// @ Block this worker's fiber until terminated; the event loop keeps serving.
trapSignal([SIGINT, SIGTERM]);

$Server->stop();
