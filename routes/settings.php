<?php

use App\Http\Controllers\Notifications\NotificationInboxController;
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
    Route::patch('notifications/inbox/{notificationLog}/read', [NotificationInboxController::class, 'markRead'])->name('notifications.inbox.read');
    Route::patch('notifications/inbox/{notificationLog}/dismiss', [NotificationInboxController::class, 'dismiss'])->name('notifications.inbox.dismiss');
    Route::delete('notifications/inbox', [NotificationInboxController::class, 'clearAll'])->name('notifications.inbox.clear');
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
});
