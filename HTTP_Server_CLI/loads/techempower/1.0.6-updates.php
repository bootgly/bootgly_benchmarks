<?php
// @label: Database updates
// @group: TechEmpower
// @opponents: all
// TechEmpower-style /updates?queries=20: pipeline 20 random World reads,
// reassign randomNumber, write them back with one batched UPDATE.

return [
   'method' => 'GET',
   'paths' => ['/updates?queries=20'],
   'expect' => [
      'status' => 200,
      'contains' => ['"id":', '"randomNumber":'],
   ],
];
