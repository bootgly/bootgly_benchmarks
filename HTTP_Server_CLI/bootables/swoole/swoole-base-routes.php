<?php
/**
 * Swoole HTTP Server — SWOOLE_BASE mode
 *
 * Reactor-based mode: each worker independently accepts connections
 * and handles requests. No master dispatching overhead.
 * Similar to Nginx's architecture.
 *
 * Same route set as all benchmark opponents:
 * 100 static + 100 dynamic + 6 nested + 3 middleware + catch-all 404.
 *
 * Usage: php swoole-base-routes.php
 */

require_once dirname(__DIR__) . '/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$serverDirectory = getenv('BENCHMARK_SERVER_DIR');
$logFile = is_string($serverDirectory) && $serverDirectory !== ''
   ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/swoole.log'
   : '/dev/null';
$PIDFile = getenv('SWOOLE_PID_FILE');
$PIDFile = is_string($PIDFile) && $PIDFile !== ''
   ? $PIDFile
   : (is_string($serverDirectory) && $serverDirectory !== ''
      ? rtrim($serverDirectory, DIRECTORY_SEPARATOR) . '/swoole.pid'
      : null);
$port = getenv('SERVER_PORT') ?: '8082';

$Server = new Server('0.0.0.0', is_numeric($port) ? (int) $port : 8082, SWOOLE_BASE);
$settings = [
   'worker_num' => (int) (getenv('SERVER_WORKER_NUM') ?: shell_exec('nproc') / 2) ?: 1,
   'daemonize' => false,
   'log_file'   => $logFile,
   'enable_reuse_port' => true,
   'http_compression'  => false,
   // Corroutine
   'enable_coroutine' => false,
   'hook_flags' => SWOOLE_HOOK_ALL,
];
if ($PIDFile !== null) {
   $settings['pid_file'] = $PIDFile;
}
$Server->set($settings);

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

$Server->on('request', function (Request $request, Response $response) use ($static) {
   if (WorkerEvidence::$enabled) {
      $identity = WorkerEvidence::identify(
         $request->header['x-bootgly-benchmark-warmup'] ?? null,
         $request->header['x-bootgly-benchmark-seal'] ?? null,
      );
      if ($identity !== null) {
         $response->header('X-Bootgly-Benchmark-Worker', $identity);
      }
   }

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

   // Extra dynamic routes (d11..d100)
   if ($n === 2 && $parts[0][0] === 'd' && ctype_digit(substr($parts[0], 1))) {
      $response->end('Dynamic ' . $parts[1]);
      return;
   }

   // Catch-all 404
   $response->status(404);
   $response->end('Not Found');
});

$Server->start();
