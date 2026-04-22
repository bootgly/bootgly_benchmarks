<?php
// @label: HTTP raw (Hello World)
// @group: HTTP Raw
// @competitors: all
// Sends a raw HTTP/1.1 GET request. Server responds with a fixed HTTP response.
// Measures TCP I/O with HTTP framing but NO routing or middleware overhead.

return [
   'message'   => "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: keep-alive\r\n\r\n",
   'delimiter' => "HTTP/1.",
];
