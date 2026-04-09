<?php
// @label: Mixed (5 static + 3 dynamic)
// @group: Mixed workloads
// @competitors: all
// Realistic workload combining both route types.

$paths = [];
for ($i = 0; $i < 800; $i++) {
   $mod = $i % 8;
   $id  = $i % 100;

   $paths[] = match ($mod) {
      0 => '/',
      1 => '/about',
      2 => '/contact',
      3 => '/blog',
      4 => '/pricing',
      5 => "/user/{$id}",
      6 => "/post/article-{$id}",
      7 => "/api/v1/resource-{$id}",
   };
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
