<?php

use App\Http\Controllers\BenchmarkController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BenchmarkController::class, 'plaintext']);
Route::get('/plaintext', [BenchmarkController::class, 'plaintext']);
Route::get('/json', [BenchmarkController::class, 'json']);
Route::get('/db', [BenchmarkController::class, 'db']);
Route::get('/query', [BenchmarkController::class, 'query']);
Route::get('/fortunes', [BenchmarkController::class, 'fortunes']);
Route::get('/updates', [BenchmarkController::class, 'updates']);
