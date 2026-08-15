<?php
// @label: Event stream throughput
// @group: SSE
// @opponents: Bootgly
// N persistent event streams; count what the server pushes down them.
//
// The offered rate is a SERVER-side knob — the client writes once and never
// again, so it cannot ask for events. `--events=N` sets events per second per
// stream, making the target `connections × events`. At the defaults that is
// 514 × 10 = 5,140 events/s.
//
// A result well under the offered rate is a real transport finding. A result
// AT the offered rate means the server kept up and the ceiling is elsewhere —
// raise `--events` until it stops keeping up.

return [
   'mode' => 'stream',
   'path' => '/sse/stream',
];
