<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GtaAlertsController;
use App\Http\Controllers\SceneIntelController;
use Illuminate\Support\Facades\Route;

Route::get('/', GtaAlertsController::class)->name('home');
Route::get('api/incidents/{eventNum}/intel', [SceneIntelController::class, 'timeline'])
    ->name('api.incidents.intel.timeline');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
