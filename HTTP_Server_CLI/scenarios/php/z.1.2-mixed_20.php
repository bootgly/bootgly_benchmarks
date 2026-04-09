<?php
// @label: Mixed (10 static + 10 dynamic)
// @group: Mixed workloads
// @competitors: all
// Realistic heavy workload combining all route types.

$statics = ['/', '/about', '/contact', '/blog', '/pricing', '/docs', '/faq', '/terms', '/privacy', '/status'];

$paths = [];
for ($i = 0; $i < 2000; $i++) {
   $mod = $i % 20;
   $id  = $i % 100;

   if ($mod < 10) {
      $paths[] = $statics[$mod];
   } else {
      $paths[] = match ($mod) {
         10 => "/user/{$id}",
         11 => "/post/article-{$id}",
         12 => "/api/v1/resource-{$id}",
         13 => "/category/cat-{$id}",
         14 => "/tag/tag-{$id}",
         15 => "/product/sku-{$id}",
         16 => "/order/ord-{$id}",
         17 => "/invoice/inv-{$id}",
         18 => "/review/rev-{$id}",
         19 => "/comment/cmt-{$id}",
      };
   }
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
