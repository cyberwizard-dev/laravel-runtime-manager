<?php

use Cyberwizard\RuntimeManager\Http\Controllers\AuthController;
use Cyberwizard\RuntimeManager\Http\Controllers\DashboardController;
use Cyberwizard\RuntimeManager\Http\Controllers\HttpController;
use Cyberwizard\RuntimeManager\Http\Controllers\LogsController;
use Cyberwizard\RuntimeManager\Http\Controllers\QueueController;
use Cyberwizard\RuntimeManager\Http\Controllers\SchedulerController;
use Cyberwizard\RuntimeManager\Http\Controllers\SetupController;
use Cyberwizard\RuntimeManager\Http\Middleware\DashboardAuth;
use Illuminate\Support\Facades\Route;

// Standalone-password auth (independent of the application's own auth).
Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->name('login.submit');
Route::post('logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/', [DashboardController::class, 'overview'])->name('dashboard');

    Route::get('queue', [QueueController::class, 'index'])->name('queue');
    Route::post('queue/retry', [QueueController::class, 'retry'])->name('queue.retry');
    Route::post('queue/forget/{uuid}', [QueueController::class, 'forget'])->name('queue.forget');
    Route::post('queue/flush', [QueueController::class, 'flush'])->name('queue.flush');

    Route::get('scheduler', [SchedulerController::class, 'index'])->name('scheduler');
    Route::get('http', [HttpController::class, 'index'])->name('http');
    Route::get('logs', [LogsController::class, 'index'])->name('logs');

    Route::get('setup', [SetupController::class, 'index'])->name('setup');
    Route::post('setup/verify', [SetupController::class, 'verify'])->name('setup.verify');
});
