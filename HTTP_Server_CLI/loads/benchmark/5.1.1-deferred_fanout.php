<?php
// @label: Deferred upstream fan-out
// @group: Reactor async probes
// @opponents: Bootgly
// One deferred response per request, each parked on its own upstream socket.
//
// Needs `--server-workers` LOW to mean anything. What this load exercises is
// the parked-descriptor population of ONE reactor, and that population is
// connections / workers — not the connection count. At the suite default of
// 514 connections the auto worker count leaves ~29 parked per worker, which
// reports a clean number that distinguishes nothing.
//
//   --server-workers=2   -> ~259 parked/worker   (the regime this load exists for)
//   --server-workers=8   ->  ~64 parked/worker   (the useful floor)
//
// Confirm the regime, never assume it: GET /reactor/peak returns the worker's
// own high-water mark. A run that reports no difference at ~1 parked has not
// measured the reactor, it has measured nothing.
//
// BENCHMARK_UPSTREAM_DELAY (ms, default 10) sets how long the upstream holds
// each connection. It moves throughput, not the regime.

return [
   'method' => 'GET',
   'paths' => ['/deferred/fanout'],
   'expect' => [
      'status' => 200,
      'contains' => ['upstream'],
   ],
];
