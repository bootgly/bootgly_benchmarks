<?php
// @label: Database single query
// @group: TechEmpower
// @opponents: all
// TechEmpower-style /db: fetch one random World row.

return [
   'method' => 'GET',
   'paths' => ['/db'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
