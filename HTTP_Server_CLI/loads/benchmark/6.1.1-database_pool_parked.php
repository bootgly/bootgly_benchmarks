<?php
// @label: Pooled database parking
// @group: Reactor async probes
// @opponents: Bootgly
// One pooled PostgreSQL connection parked per request.
//
// The reactor regime here is `DB_POOL_MAX`, and nothing else. Offered load
// past the ceiling queues on the pool instead of adding parked descriptors,
// so the worker's peak equals the ceiling exactly:
//
//   DB_POOL_MAX=1    -> peak 1    (measures nothing — this is the default)
//   DB_POOL_MAX=16   -> peak 16
//   DB_POOL_MAX=64   -> peak 64   (the regime this load exists for)
//
// It is deliberately NOT a `4.x` database load. Those are pinned to a single
// connection per worker by the cross-framework parity contract — which is
// what makes them comparable, and what makes them useless as a reactor probe.
// Selecting any of them with DB_POOL_MAX > 1 is refused, by design.
//
// Because this load sits outside that contract it declares no `readiness`:
// there is no per-worker database slot proof, exactly like the fan-out probe
// it sits beside. Confirm the regime instead of assuming it — GET
// /reactor/peak returns the worker's own high-water mark, and the response
// body carries the pool occupancy the run actually reached. A `created`
// below the ceiling means the pool never filled and the number below it is
// not the regime you configured.
//
// `--server-workers` × DB_POOL_MAX MUST fit under the database server's
// `max_connections` (PostgreSQL defaults to 100). Exceeding it does not fail
// as a database error — the workers that cannot open their pool never answer,
// and the run dies in the preflight with "Worker warmup could not prove the
// complete worker/path matrix", which reads like a harness fault and is not
// one. At the suite's auto worker count this ceiling is reached early:
// 12 workers × 16 = 192 connections against a default server.
//
// Throughput here has a closed form — every slot serves one query per delay:
//
//   expected req/s = (--server-workers × DB_POOL_MAX) / BENCHMARK_POOL_DELAY
//
// Measured at 4 workers, delay 0.05s: pool 4 -> 318 (exp 320), pool 8 -> 626
// (exp 640), pool 16 -> 1267 (exp 1280). A run landing well under its own
// expectation is a real finding, not noise — the pool-bound ceiling is what
// this load pins, so a departure means the reactor stopped keeping the parked
// population busy.
//
// BENCHMARK_POOL_DELAY (seconds, default 0.05) sets how long each query holds
// its connection. It moves throughput, not the regime.

return [
   'method' => 'GET',
   'paths' => ['/database/pool/parked'],
   'expect' => [
      'status' => 200,
      'contains' => ['"errors":[]', '"pool"'],
   ],
];
