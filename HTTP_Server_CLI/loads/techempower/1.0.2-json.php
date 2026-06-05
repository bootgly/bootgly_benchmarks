<?php
// @label: JSON
// @group: TechEmpower
// @competitors: all
// TechEmpower-style /json: application/json {"message":"Hello, World!"}.

return [
   'method' => 'GET',
   'paths' => ['/json'],
   'expect' => [
      'status' => 200,
      'contains' => ['"message":"Hello, World!"'],
   ],
];
