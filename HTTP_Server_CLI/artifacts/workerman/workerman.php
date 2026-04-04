<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */
require_once 'vendor/autoload.php';

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Protocols\Http\Response;
use Workerman\Protocols\Http\Request;

// #### http worker ####
$http_worker = new Worker('http://0.0.0.0:8082');

$http_worker->count = 12;

$http_worker->onWorkerStart = function () {
    Header::$date = gmdate('D, d M Y H:i:s').' GMT';

    Timer::add(1, function() {
        Header::$date = gmdate('D, d M Y H:i:s').' GMT';
    });
};

// Emitted when data received
$http_worker->onMessage = function ($connection, $request) {
    //$request->get();
    //$request->post();
    //$request->header();
    //$request->cookie();
    //$request->session();
    //$request->uri();
    // $request->path();
    //$request->method();

    // Send data to client
    $connection->send(new Response(200, [
        'Content-Type' => 'text/plain',
        'Date'         => Header::$date
    ], 'Hello, World!'));
};

// Run all workers
Worker::runAll();

class Header {
    public static $date = null;
}

