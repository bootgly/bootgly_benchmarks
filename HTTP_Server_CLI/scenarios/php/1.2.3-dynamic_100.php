<?php
// @label: 100 dynamic routes
// @group: Dynamic routes
// @competitors: all
// Round-robins through all 100 registered dynamic routes.

$prefixes = ['/user/', '/post/article-', '/api/v1/resource-', '/category/cat-', '/tag/tag-', '/product/sku-', '/order/ord-', '/invoice/inv-', '/review/rev-', '/comment/cmt-'];
for ($i = 11; $i <= 100; $i++) {
   $prefixes[] = "/d{$i}/";
}

$paths = [];
for ($i = 0; $i < 1000; $i++) {
   $idx = $i % count($prefixes);
   $id  = $i % 100;
   $paths[] = $prefixes[$idx] . $id;
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
