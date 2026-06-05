<?php
// @label: Database resource multi-query
// @group: Database Bootgly resources
// @opponents: Bootgly
// Response resource ADI PostgreSQL pool/concurrent operations.

return [
   'method' => 'GET',
   'paths' => ['/database/resource/pool'],
   'expect' => [
      'status' => 200,
      'contains' => ['"errors":[]', '"value":1', '"value":2', '"value":3'],
   ],
];
