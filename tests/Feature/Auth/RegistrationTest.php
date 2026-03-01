<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('user registration sanitizes input correctly', function () {
    $response = $this->post('/register', [
        'name' => '<b>Test User</b>',
        'email' => 'test2@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'test2@example.com',
        'name' => 'Test User',
    ]);
});

test('user registration does not crash on array input', function () {
    $response = $this->post('/register', [
        'name' => ['a' => 'b'],
        'email' => 'test3@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertSessionHasErrors(['name']);
});
