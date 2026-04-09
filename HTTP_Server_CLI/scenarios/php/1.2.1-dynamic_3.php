<?php
// @label: 1 dynamic route
// @group: Dynamic routes
// @competitors: all
// Hits /user/:id with rotating IDs.

$paths = [];
for ($i = 0; $i < 100; $i++) {
   $paths[] = "/user/{$i}";
}

return [
   'method' => 'GET',
   'paths'  => $paths,
];
