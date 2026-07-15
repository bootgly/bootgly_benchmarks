<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/../../../runners/Profiles.php';
require_once dirname(__DIR__) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Request;
use Bootgly\Benchmarks\Runners\Profiles;

if (extension_loaded('pdo_pgsql') === false) {
    fwrite(STDERR, "Workerman TechEmpower opponent requires the pdo_pgsql extension.\n");
    exit(1);
}

$http_worker = new Worker('http://0.0.0.0:8082');
$http_worker->count = (int) (getenv('SERVER_WORKER_NUM') ?: 12);

// Per-worker state: ONE persistent PDO + the in-memory CachedWorld pool.
$pdo = null;
$cachedWorlds = [];

$http_worker->onWorkerStart = function () use (&$pdo, &$cachedWorlds) {
    WorkerEvidence::boot();
    Header::$date = gmdate('D, d M Y H:i:s').' GMT';
    Timer::add(1, function() {
        Header::$date = gmdate('D, d M Y H:i:s').' GMT';
    });

    // Per-worker sampling profiler (env-gated) — mirrors Bootgly's
    // projects/Benchmark/HTTP_Server_CLI/Profiler.php for fair comparison.
    if (getenv('BOOTGLY_PROFILE') === '1' && class_exists(ExcimerProfiler::class)) {
        $Profiler = new ExcimerProfiler;
        $Profiler->setPeriod(0.0001);
        $Profiler->setEventType(EXCIMER_CPU);
        $Profiler->setMaxDepth(64);
        $Profiler->start();
        register_shutdown_function(function () use ($Profiler) {
            $Profiler->stop();
            $directory = Profiles::resolve('server', '/tmp/workerman_profile');
            $file = $directory . '/worker-' . getmypid() . '.collapsed';
            if (Profiles::publish($file, $Profiler->getLog()->formatCollapsed()) === false) {
                fwrite(STDERR, "ERROR: Cannot publish server profile: $file\n");
            }
        });
    }

    // ONE persistent PDO per worker, created once and reused for the worker lifetime.
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_NAME') ?: 'bootgly';
    $user = getenv('DB_USER') ?: 'postgres';
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Prime the per-worker in-memory CachedWorld pool for /cached-queries (no DB on hot path).
    $cache = [];
    foreach ($pdo->query('SELECT id, randomNumber AS "randomNumber" FROM CachedWorld') as $row) {
        $id = (int) ($row['id'] ?? 0);
        $cache[$id] = [
            'id' => $id,
            'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
        ];
    }
    $cachedWorlds = $cache;
};

// Clamp helper: TFB queries/count param, max(1, min(500, (int) $value)), missing/invalid => 1.
$clamp = function ($value): int {
    if (is_array($value)) {
        $value = $value[0] ?? 1;
    }

    return max(1, min(500, (int) $value));
};

// Single World read: SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?.
$fetchWorld = function (PDOStatement $Statement, int $id): array {
    $Statement->execute([$id]);
    $row = $Statement->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (int) ($row['id'] ?? 0),
        'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
    ];
};

$http_worker->onMessage = function ($connection, Request $request) use (&$pdo, &$cachedWorlds, $clamp, $fetchWorld) {
    $path = $request->path();
    $identity = null;
    if (WorkerEvidence::$enabled) {
        $identity = WorkerEvidence::identify(
            $request->header('x-bootgly-benchmark-warmup'),
            $request->header('x-bootgly-benchmark-nonce'),
            $request->header('x-bootgly-benchmark-seal'),
        );
    }

    // TechEmpower /plaintext + /json: static, no DB. Handle early.
    if ($path === '/plaintext') {
        $connection->send(new Response(200, $identity === null
            ? [
                'Content-Type' => 'text/plain',
                'Date'         => Header::$date,
            ]
            : [
                'Content-Type' => 'text/plain',
                'Date'         => Header::$date,
                'X-Bootgly-Benchmark-Worker' => $identity,
            ], 'Hello, World!'));
        return;
    }
    if ($path === '/json') {
        $connection->send(new Response(200, $identity === null
            ? [
                'Content-Type' => 'application/json',
                'Date'         => Header::$date,
            ]
            : [
                'Content-Type' => 'application/json',
                'Date'         => Header::$date,
                'X-Bootgly-Benchmark-Worker' => $identity,
            ], '{"message":"Hello, World!"}'));
        return;
    }

    try {
        $contentType = 'application/json';

        switch ($path) {
            case '/db':
                $Statement = $pdo->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
                $World = $fetchWorld($Statement, mt_rand(1, 10000));
                $body = json_encode($World, JSON_NUMERIC_CHECK) ?: '{}';
                break;

            case '/query':
                $queries = $clamp($request->get('queries'));
                $Statement = $pdo->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
                $Worlds = [];
                while ($queries-- > 0) {
                    $Worlds[] = $fetchWorld($Statement, mt_rand(1, 10000));
                }
                $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
                break;

            case '/fortunes':
                $contentType = 'text/html; charset=utf-8';
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
                break;

            case '/updates':
                $queries = $clamp($request->get('queries'));
                $Statement = $pdo->prepare('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = ?');
                $Worlds = [];
                while ($queries-- > 0) {
                    $World = $fetchWorld($Statement, mt_rand(1, 10000));
                    $World['randomNumber'] = mt_rand(1, 10000);
                    $Worlds[] = $World;
                }

                // ONE batched write: UPDATE World SET randomNumber = CASE id WHEN ? THEN ? ... END WHERE id IN (?, ...).
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
                    $Update = $pdo->prepare('UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')');
                    $Update->execute($parameters);
                }

                $body = json_encode($Worlds, JSON_NUMERIC_CHECK) ?: '[]';
                break;

            case '/cached-queries':
                $count = $clamp($request->get('count'));
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
                $connection->send(new Response(200, $identity === null
                    ? [
                        'Content-Type' => 'text/plain',
                        'Date'         => Header::$date,
                    ]
                    : [
                        'Content-Type' => 'text/plain',
                        'Date'         => Header::$date,
                        'X-Bootgly-Benchmark-Worker' => $identity,
                    ], 'TechEmpower Benchmark'));
                return;

            default:
                $connection->send(new Response(404, $identity === null
                    ? [
                        'Content-Type' => 'text/plain',
                        'Date'         => Header::$date,
                    ]
                    : [
                        'Content-Type' => 'text/plain',
                        'Date'         => Header::$date,
                        'X-Bootgly-Benchmark-Worker' => $identity,
                    ], 'Not Found'));
                return;
        }

        $connection->send(new Response(200, $identity === null
            ? [
                'Content-Type' => $contentType,
                'Date'         => Header::$date,
            ]
            : [
                'Content-Type' => $contentType,
                'Date'         => Header::$date,
                'X-Bootgly-Benchmark-Worker' => $identity,
            ], $body));
    }
    catch (Throwable $Throwable) {
        $connection->send(new Response(500, $identity === null
            ? [
                'Content-Type' => 'text/plain',
                'Date'         => Header::$date,
            ]
            : [
                'Content-Type' => 'text/plain',
                'Date'         => Header::$date,
                'X-Bootgly-Benchmark-Worker' => $identity,
            ], $Throwable->getMessage()));
    }
};

Worker::runAll();

class Header {
    public static $date = null;
}
