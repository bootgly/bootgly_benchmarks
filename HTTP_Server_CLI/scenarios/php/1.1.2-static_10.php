<?php
// @label: 10 static routes
// @group: Static routes
// @competitors: all
// Round-robins through all 10 registered static routes.

return [
   'method' => 'GET',
   'paths'  => ['/', '/about', '/contact', '/blog', '/pricing', '/docs', '/faq', '/terms', '/privacy', '/status'],
];
