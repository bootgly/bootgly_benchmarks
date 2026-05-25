<?php
// @label: Database updates
// @group: Database TechEmpower
// @competitors: Bootgly,Swoole Database
// TechEmpower-style /updates?queries=N: fetch N random World rows and update randomNumber.

return [
   'method' => 'GET',
   'paths' => ['/updates?queries=20'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
