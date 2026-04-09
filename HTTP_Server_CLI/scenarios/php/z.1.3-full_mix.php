<?php
// @label: Full mix (all types)
// @group: Mixed workloads
// @competitors: all
// Realistic workload: static + dynamic + nested + middleware + catch-all.

$paths = [];
for ($i = 0; $i < 1500; $i++) {
   $mod = $i % 15;
   $id  = $i % 100;

   $paths[] = match ($mod) {
      // Static routes (5)
      0  => '/',
      1  => '/about',
      2  => '/blog',
      3  => '/docs',
      4  => '/status',
      // Dynamic routes (3)
      5  => "/user/{$id}",
      6  => "/post/article-{$id}",
      7  => "/product/sku-{$id}",
      // Nested routes (3)
      8  => '/admin/dashboard',
      9  => '/admin/users',
      10 => '/account/profile',
      // Middleware routes (2)
      11 => '/protected/dashboard',
      12 => '/protected/settings',
      // Catch-all (2)
      13 => "/unknown-{$id}",
      14 => '/missing-page',
   };
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
