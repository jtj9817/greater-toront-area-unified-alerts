<?php

use App\Models\SavedAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('saved_alert model has expected fillable attributes', function () {
    $alert = new SavedAlert;

    expect($alert->getFillable())->toBe([
        'user_id',
        'alert_id',
    ]);
});

test('saved_alert model user relationship returns belongs to user', function () {
    $alert = new SavedAlert;

    expect($alert->user())->toBeInstanceOf(BelongsTo::class);
    expect($alert->user()->getRelated())->toBeInstanceOf(User::class);
});

test('saved_alert factory creates a persisted alert with colon-separated alert_id', function () {
    $alert = SavedAlert::factory()->create();

    expect($alert->exists)->toBeTrue();
    expect($alert->alert_id)->toContain(':');
    expect($alert->user_id)->not->toBeNull();
});

test('saved_alert factory creates alert belonging to the specified user', function () {
    $user = User::factory()->create();
    $alert = SavedAlert::factory()->create(['user_id' => $user->id]);

    expect($alert->user->is($user))->toBeTrue();
});

test('saved_alert unique constraint rejects duplicate user_id and alert_id pair', function () {
    $user = User::factory()->create();
    SavedAlert::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'fire:F20260001',
    ]);

    expect(fn () => SavedAlert::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'fire:F20260001',
    ]))->toThrow(UniqueConstraintViolationException::class);
});
