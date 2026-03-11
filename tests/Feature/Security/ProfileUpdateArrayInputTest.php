<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('ProfileUpdateRequest correctly rejects array input for name', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => ['an array'],
            'email' => 'test@example.com',
        ], [
            'Accept' => 'application/json'
        ]);

    $response->assertStatus(422); // Should fail validation, not cause an Array to string conversion warning/error
});
