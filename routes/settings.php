<?php

use App\Http\Controllers\Geocoding\LocalGeocodingSearchController;
use App\Http\Controllers\Notifications\NotificationInboxController;
use App\Http\Controllers\Notifications\SavedAlertController;
use App\Http\Controllers\Notifications\SavedPlaceController;
use App\Http\Controllers\Notifications\SubscriptionOptionsController;
use App\Http\Controllers\SceneIntelController;
use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('settings/notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
    Route::patch('settings/notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
    Route::get('notifications/inbox', [NotificationInboxController::class, 'index'])->name('notifications.inbox.index');
    Route::patch('notifications/inbox/read-all', [NotificationInboxController::class, 'markAllRead'])->name('notifications.inbox.read-all');
    Route::patch('notifications/inbox/{notificationLog}/read', [NotificationInboxController::class, 'markRead'])->name('notifications.inbox.read');
    Route::patch('notifications/inbox/{notificationLog}/dismiss', [NotificationInboxController::class, 'dismiss'])->name('notifications.inbox.dismiss');
    Route::delete('notifications/inbox', [NotificationInboxController::class, 'clearAll'])->name('notifications.inbox.clear');
    Route::get('api/geocoding/search', LocalGeocodingSearchController::class)->name('api.geocoding.search');
    Route::get('api/subscriptions/options', SubscriptionOptionsController::class)->name('api.subscriptions.options');
    Route::get('api/saved-places', [SavedPlaceController::class, 'index'])->name('api.saved-places.index');
    Route::post('api/saved-places', [SavedPlaceController::class, 'store'])->name('api.saved-places.store');
    Route::patch('api/saved-places/{savedPlace}', [SavedPlaceController::class, 'update'])->name('api.saved-places.update');
    Route::delete('api/saved-places/{savedPlace}', [SavedPlaceController::class, 'destroy'])->name('api.saved-places.destroy');
    Route::get('api/saved-alerts', [SavedAlertController::class, 'index'])->name('api.saved-alerts.index');
    Route::post('api/saved-alerts', [SavedAlertController::class, 'store'])->name('api.saved-alerts.store');
    Route::delete('api/saved-alerts/{alertId}', [SavedAlertController::class, 'destroy'])->name('api.saved-alerts.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::post('api/incidents/{eventNum}/intel', [SceneIntelController::class, 'store'])
        ->middleware('can:scene-intel.create-manual-entry')
        ->name('api.incidents.intel.store');
});
