<?php
// @label: Database multiple queries
// @group: Database TechEmpower
// @competitors: Bootgly,Swoole Database
// TechEmpower-style /query?queries=N: fetch N random World rows.

return [
   'method' => 'GET',
   'paths' => ['/query?queries=20'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
