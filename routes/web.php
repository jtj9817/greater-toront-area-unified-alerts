<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GtaAlertsController;
use Illuminate\Support\Facades\Route;

Route::get('/', GtaAlertsController::class)->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
