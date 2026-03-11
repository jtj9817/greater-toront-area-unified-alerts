<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('CreateNewUser action correctly rejects array input for name instead of throwing TypeError', function () {
    $response = $this->post('/register', [
        'name' => ['an array'],
        'email' => 'test@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertStatus(422); // Should fail validation, not 500
});
