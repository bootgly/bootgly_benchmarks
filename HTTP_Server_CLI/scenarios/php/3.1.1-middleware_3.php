<?php
// @label: 3 middleware routes
// @group: Middleware routes
// @competitors: bootgly
// Round-robins through protected routes that apply middleware.

return [
   'method' => 'GET',
   'paths'  => ['/protected/dashboard', '/protected/settings', '/protected/profile'],
];
