<?php
/**
 * RoadRunner HTTP Worker — Benchmark Routes
 *
 * Same route set as the Bootgly, Swoole and Workerman benchmarks:
 * 100 static + 100 dynamic + 6 nested + 3 middleware + catch-all 404.
 *
 * Usage: ./rr serve -c .rr.yaml
 * (This worker is managed by the RoadRunner Go binary via goridge pipes)
 */

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\Http\PSR7Worker;

$worker  = Worker::create();
$factory = new Psr17Factory();
$psr7    = new PSR7Worker($worker, $factory, $factory, $factory);

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

$headers = ['Content-Type' => 'text/plain'];

while ($request = $psr7->waitRequest()) {
   try {
      $path = $request->getUri()->getPath();

      // Static routes
      if (isset($static[$path])) {
         $psr7->respond(new Response(200, $headers, $static[$path]));
         continue;
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
            $psr7->respond(new Response(200, $headers, $body));
            continue;
         }
      }

      if ($n === 3 && $parts[0] === 'api' && $parts[1] === 'v1') {
         $psr7->respond(new Response(200, $headers, 'API: ' . $parts[2]));
         continue;
      }

      // Extra dynamic routes (d11..d100)
      if ($n === 2 && $parts[0][0] === 'd' && ctype_digit(substr($parts[0], 1))) {
         $psr7->respond(new Response(200, $headers, 'Dynamic ' . $parts[1]));
         continue;
      }

      // Catch-all 404
      $psr7->respond(new Response(404, $headers, 'Not Found'));
   } catch (\Throwable $e) {
      $psr7->respond(new Response(500, $headers, 'Internal Server Error'));
   }
}
