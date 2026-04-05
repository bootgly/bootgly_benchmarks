<?php
/**
 * Swoole HTTP Server — Coroutine mode
 *
 * Uses Swoole\Coroutine\Http\Server inside Co\run().
 * Multi-process via pcntl_fork() + SO_REUSEPORT for fair comparison.
 * Each forked process runs its own coroutine HTTP server on the same port.
 *
 * Same route set as all benchmark competitors:
 * 10 static + 10 dynamic + 6 nested + 3 middleware + catch-all 404.
 *
 * Usage: php swoole-coroutine-routes.php
 */

use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use function Swoole\Coroutine\run;

$workers = (int) (getenv('SERVER_WORKER_NUM') ?: shell_exec('nproc') / 2) ?: 1;
$host = '0.0.0.0';
$port = 8082;

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

// Write PID file for daemon management
file_put_contents(__DIR__ . '/swoole-coroutine.pid', getmypid());

// Fork workers (parent + children = $workers total)
$pids = [];
for ($i = 1; $i < $workers; $i++) {
   $pid = pcntl_fork();
   if ($pid === 0) {
      // Child process — break out and start serving
      break;
   }
   if ($pid > 0) {
      $pids[] = $pid;
   }
}

run(function () use ($host, $port, $static) {
   $server = new Server($host, $port, false, true); // SO_REUSEPORT = true

   $server->handle('/', function (Request $request, Response $response) use ($static) {
      $path = $request->server['request_uri'];

      $response->header('Content-Type', 'text/plain');

      // Static routes
      if (isset($static[$path])) {
         $response->end($static[$path]);
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
            $response->end($body);
            return;
         }
      }

      if ($n === 3 && $parts[0] === 'api' && $parts[1] === 'v1') {
         $response->end('API: ' . $parts[2]);
         return;
      }

      // Catch-all 404
      $response->status(404);
      $response->end('Not Found');
   });

   $server->start();
});
