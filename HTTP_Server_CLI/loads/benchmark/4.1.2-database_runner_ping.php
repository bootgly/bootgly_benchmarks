<?php
// @label: Database resource query
// @group: Database Bootgly resources
// @opponents: Bootgly
// ADI PostgreSQL ping through HTTP_Server_CLI Response\Resources\Database.

return [
   'method' => 'GET',
   'paths' => ['/database/resource/ping'],
   'expect' => [
      'status' => 200,
      'contains' => ['"ok":1'],
   ],
];
