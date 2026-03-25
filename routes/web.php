<?php

use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GtaAlertsController;
use App\Http\Controllers\SceneIntelController;
use App\Http\Controllers\Weather\PostalCodeResolveCoordsController;
use App\Http\Controllers\Weather\PostalCodeSearchController;
use App\Http\Controllers\Weather\WeatherController;
use Illuminate\Support\Facades\Route;

Route::get('/', GtaAlertsController::class)->name('home');
Route::get('api/feed', FeedController::class)
    ->middleware('throttle:120,1')
    ->name('api.feed');
Route::get('api/incidents/{eventNum}/intel', [SceneIntelController::class, 'timeline'])
    ->middleware('throttle:60,1')
    ->name('api.incidents.intel.timeline');

Route::get('api/postal-codes', PostalCodeSearchController::class)
    ->middleware('throttle:60,1')
    ->name('api.postal-codes.search');
Route::post('api/postal-codes/resolve-coords', PostalCodeResolveCoordsController::class)
    ->middleware('throttle:60,1')
    ->name('api.postal-codes.resolve-coords');
Route::get('api/weather', WeatherController::class)
    ->middleware('throttle:60,1')
    ->name('api.weather');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
