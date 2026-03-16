<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('profile name array payload does not convert to literal Array string', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->patchJson(route('profile.update'), [
            'name' => ['malicious' => 'input'],
            'email' => 'test@example.com',
        ]);

    $response->assertStatus(422);
    $user->refresh();
    expect($user->name)->not->toBe('Array');
});
