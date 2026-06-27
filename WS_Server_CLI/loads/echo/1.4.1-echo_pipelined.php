<?php
// @label: Echo 32B (pipelined x16)
// @group: Echo
// @opponents: all
// 16 frames in flight per connection (the HTTP plaintext pipelining depth),
// shifting the measurement from round-trip latency to frame-processing
// throughput — the regime in which HTTP plaintext reaches its peak. Latency
// here is queue-inclusive (each frame waits behind the others), not a clean
// round trip.

return [
   'mode'     => 'echo',
   'payload'  => str_repeat('x', 32),
   'binary'   => false,
   'pipeline' => 16,
];
