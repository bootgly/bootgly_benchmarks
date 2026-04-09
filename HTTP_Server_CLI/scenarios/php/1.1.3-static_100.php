<?php
// @label: 100 static routes
// @group: Static routes
// @competitors: all
// Round-robins through all 100 registered static routes.

$paths = ['/', '/about', '/contact', '/blog', '/pricing', '/docs', '/faq', '/terms', '/privacy', '/status'];
for ($i = 11; $i <= 100; $i++) {
   $paths[] = "/static/{$i}";
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
