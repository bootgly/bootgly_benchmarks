<?php
// @label: 10 dynamic routes
// @group: Dynamic routes
// @competitors: all
// Round-robins through all 10 registered dynamic routes.

$paths = [];
for ($i = 0; $i < 1000; $i++) {
   $mod = $i % 10;
   $id  = $i % 100;

   $paths[] = match ($mod) {
      0 => "/user/{$id}",
      1 => "/post/article-{$id}",
      2 => "/api/v1/resource-{$id}",
      3 => "/category/cat-{$id}",
      4 => "/tag/tag-{$id}",
      5 => "/product/sku-{$id}",
      6 => "/order/ord-{$id}",
      7 => "/invoice/inv-{$id}",
      8 => "/review/rev-{$id}",
      9 => "/comment/cmt-{$id}",
   };
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
