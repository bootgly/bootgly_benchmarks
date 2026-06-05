<?php
// @label: Database multiple queries
// @group: TechEmpower
// @opponents: all
// TechEmpower-style /query?queries=20: pipeline 20 random World fetches.

return [
   'method' => 'GET',
   'paths' => ['/query?queries=20'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
