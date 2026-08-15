<?php
// @label: Event stream open rate
// @group: SSE
// @opponents: Bootgly
// How fast the server can ESTABLISH an event stream: open, read the head,
// close, repeat.
//
// Serial per client worker on purpose. Holding many opens in flight would
// measure the accept backlog instead of the per-stream cost, which is what
// actually bounds a reconnect storm — every browser retrying at once after a
// deploy is exactly this shape.
//
// Latency here is the time from dial to a complete `text/event-stream` head.

return [
   'mode' => 'open',
   'path' => '/sse/stream',
];
