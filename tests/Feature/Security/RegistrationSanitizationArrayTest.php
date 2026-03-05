<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationSanitizationArrayTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_name_handles_array(): void
    {
        $response = $this->post('/register', [
            'name' => ['malicious' => 'input'],
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }
}
