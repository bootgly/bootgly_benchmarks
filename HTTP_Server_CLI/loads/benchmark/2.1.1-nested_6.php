<?php
// @label: 6 nested routes (2 groups)
// @group: Nested routes
// @opponents: all
// Round-robins through admin and account group routes.

return [
   'method' => 'GET',
   'paths'  => [
      '/admin/dashboard', '/admin/settings', '/admin/users',
      '/account/profile', '/account/billing', '/account/security',
   ],
];
