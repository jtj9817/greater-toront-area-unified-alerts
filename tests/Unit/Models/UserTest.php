<?php

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('user model has expected fillable hidden and casts', function () {
    $user = new User([
        'name' => 'Taylor Otwell',
        'email' => 'taylor@example.test',
        'password' => 'plain-text-password',
    ]);
    $user->forceFill([
        'email_verified_at' => '2026-02-24 12:00:00',
        'two_factor_confirmed_at' => '2026-02-24 12:10:00',
    ]);

    expect($user->getFillable())->toBe([
        'name',
        'email',
        'password',
    ]);

    expect($user->getHidden())->toBe([
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ]);

    expect($user->getAttributes()['password'])->not->toBe('plain-text-password');
    expect(Hash::check('plain-text-password', $user->getAttributes()['password']))->toBeTrue();

    expect($user->email_verified_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($user->two_factor_confirmed_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('user model relationships are configured correctly', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    NotificationPreference::factory()->create(['user_id' => $user->id]);
    NotificationPreference::factory()->create(['user_id' => $otherUser->id]);
    NotificationLog::factory()->count(2)->create(['user_id' => $user->id]);
    NotificationLog::factory()->create(['user_id' => $otherUser->id]);
    SavedPlace::factory()->count(3)->create(['user_id' => $user->id]);
    SavedPlace::factory()->create(['user_id' => $otherUser->id]);

    $user = $user->fresh();

    expect($user->notificationPreference)->toBeInstanceOf(NotificationPreference::class);
    expect($user->notificationPreference->user_id)->toBe($user->id);
    expect($user->notificationLogs)->toHaveCount(2);
    expect($user->notificationLogs->first())->toBeInstanceOf(NotificationLog::class);
    expect($user->notificationLogs->pluck('user_id')->unique()->all())->toBe([$user->id]);
    expect($user->savedPlaces)->toHaveCount(3);
    expect($user->savedPlaces->first())->toBeInstanceOf(SavedPlace::class);
    expect($user->savedPlaces->pluck('user_id')->unique()->all())->toBe([$user->id]);
});
