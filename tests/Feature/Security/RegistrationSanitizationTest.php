<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_name_is_sanitized(): void
    {
        $response = $this->post('/register', [
            'name' => 'John <script>alert("XSS")</script> Doe',
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        // Expectation: The name should be sanitized (tags stripped)
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'John alert("XSS") Doe',
        ]);

        // Ensure the raw malicious script is NOT present
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
            'name' => 'John <script>alert("XSS")</script> Doe',
        ]);
    }
}
