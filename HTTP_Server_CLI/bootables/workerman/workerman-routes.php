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

$http_worker = new Worker('http://0.0.0.0:8082');
$http_worker->count = (int) (getenv('SERVER_WORKER_NUM') ?: 12);

$http_worker->onWorkerStart = function () {
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
};

// Static routes
$static = [
    '/'        => 'Home',
    '/about'   => 'About',
    '/contact' => 'Contact',
    '/blog'    => 'Blog',
    '/pricing' => 'Pricing',
    '/docs'    => 'Docs',
    '/faq'     => 'FAQ',
    '/terms'   => 'Terms',
    '/privacy' => 'Privacy',
    '/status'  => 'Status',
];
// Extra static routes (11..100)
for ($i = 11; $i <= 100; $i++) {
    $static["/static/{$i}"] = "Static {$i}";
}

$http_worker->onMessage = function ($connection, Request $request) use ($static) {
    $path = $request->path();
    $headers = [
        'Content-Type' => 'text/plain',
        'Date'         => Header::$date
    ];
    if (WorkerEvidence::$enabled) {
        $identity = WorkerEvidence::identify(
            $request->header('x-bootgly-benchmark-warmup'),
            $request->header('x-bootgly-benchmark-seal'),
        );
        if ($identity !== null) {
            $headers['X-Bootgly-Benchmark-Worker'] = $identity;
        }
    }

    if (isset($static[$path])) {
        $connection->send(new Response(200, $headers, $static[$path]));
        return;
    }

    // Dynamic routes (10)
    $parts = explode('/', ltrim($path, '/'));
    $n = count($parts);

    if ($n === 2) {
        $body = match ($parts[0]) {
            'user'     => 'User: ' . $parts[1],
            'post'     => 'Post: ' . $parts[1],
            'category' => 'Category: ' . $parts[1],
            'tag'      => 'Tag: ' . $parts[1],
            'product'  => 'Product: ' . $parts[1],
            'order'    => 'Order: ' . $parts[1],
            'invoice'  => 'Invoice: ' . $parts[1],
            'review'   => 'Review: ' . $parts[1],
            'comment'  => 'Comment: ' . $parts[1],
            // Nested routes (6)
            'admin' => match ($parts[1]) {
                'dashboard' => 'Admin Dashboard',
                'settings'  => 'Admin Settings',
                'users'     => 'Admin Users',
                default     => null,
            },
            'account' => match ($parts[1]) {
                'profile'  => 'Account Profile',
                'billing'  => 'Account Billing',
                'security' => 'Account Security',
                default    => null,
            },
            // Middleware routes (3)
            'protected' => match ($parts[1]) {
                'dashboard' => 'Protected Dashboard',
                'settings'  => 'Protected Settings',
                'profile'   => 'Protected Profile',
                default     => null,
            },
            default => null,
        };

        if ($body !== null) {
            $connection->send(new Response(200, $headers, $body));
            return;
        }
    }

    if ($n === 3 && $parts[0] === 'api' && $parts[1] === 'v1') {
        $connection->send(new Response(200, $headers, 'API: ' . $parts[2]));
        return;
    }

    // Extra dynamic routes (d11..d100)
    if ($n === 2 && $parts[0][0] === 'd' && ctype_digit(substr($parts[0], 1))) {
        $connection->send(new Response(200, $headers, 'Dynamic ' . $parts[1]));
        return;
    }

    // Catch-all 404
    $connection->send(new Response(404, $headers, 'Not Found'));
};

Worker::runAll();

class Header {
    public static $date = null;
}
