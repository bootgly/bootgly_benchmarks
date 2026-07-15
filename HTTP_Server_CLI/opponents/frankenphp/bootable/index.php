<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootables/WorkerEvidence.php';

use Bootgly\Benchmarks\HTTP_Server_CLI\WorkerEvidence;


// Static routes lookup table.
$routes = [
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

for ($i = 11; $i <= 100; $i++) {
   $routes["/static/{$i}"] = "Static {$i}";
}

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

WorkerEvidence::boot();

for ($requestNumber = 0; !$maxRequests || $requestNumber < $maxRequests; $requestNumber++) {
   $keepRunning = \frankenphp_handle_request(static function () use ($routes): void {
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

      if (isset($routes[$path])) {
         header('Content-Type: text/plain');
         echo $routes[$path];

         return;
      }

      $parts = explode('/', ltrim($path, '/'));
      $partsCount = count($parts);

      if ($partsCount === 2) {
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
            'admin'    => match ($parts[1]) {
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
            'protected' => match ($parts[1]) {
               'dashboard' => 'Protected Dashboard',
               'settings'  => 'Protected Settings',
               'profile'   => 'Protected Profile',
               default     => null,
            },
            default => null,
         };

         if ($body !== null) {
            header('Content-Type: text/plain');
            echo $body;

            return;
         }
      }

      if ($partsCount === 3 && $parts[0] === 'api' && $parts[1] === 'v1') {
         header('Content-Type: text/plain');
         echo 'API: ' . $parts[2];

         return;
      }

      if (
         $partsCount === 2
         && isset($parts[0][0])
         && $parts[0][0] === 'd'
         && ctype_digit(substr($parts[0], 1))
      ) {
         header('Content-Type: text/plain');
         echo 'Dynamic ' . $parts[1];

         return;
      }

      http_response_code(404);
      header('Content-Type: text/plain');
      echo 'Not Found';
   });

   gc_collect_cycles();

   if (!$keepRunning) {
      break;
   }
}
