<?php
// @label: Database fortunes
// @group: Database TechEmpower
// @competitors: Bootgly,Swoole Database
// TechEmpower-style /fortunes: fetch, append, sort, escape, and render Fortune rows.

return [
   'method' => 'GET',
   'paths' => ['/fortunes'],
   'expect' => [
      'status' => 200,
      'contains' => ['<table>', 'Additional fortune added at request time.'],
   ],
];
