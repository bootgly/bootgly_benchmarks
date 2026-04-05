<?php
/**
 * Swoole HTTP Server — SWOOLE_BASE mode
 *
 * Reactor-based mode: each worker independently accepts connections
 * and handles requests. No master dispatching overhead.
 * Similar to Nginx's architecture.
 *
 * Same route set as all benchmark competitors:
 * 10 static + 10 dynamic + 6 nested + 3 middleware + catch-all 404.
 *
 * Usage: php swoole-base-routes.php
 */

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$server = new Server('0.0.0.0', 8082, SWOOLE_BASE);
$server->set([
   'worker_num' => (int) (getenv('SERVER_WORKER_NUM') ?: shell_exec('nproc') / 2) ?: 1,
   'daemonize'  => true,
   'log_file'   => '/dev/null',
]);

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

$server->on('request', function (Request $request, Response $response) use ($static) {
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
