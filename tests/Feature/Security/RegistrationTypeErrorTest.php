<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTypeErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_with_array_name_does_not_crash(): void
    {
        $response = $this->post('/register', [
            'name' => ['an array'],
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        // It should either redirect back with errors or return a 422 JSON response
        $response->assertStatus(302);
        $response->assertSessionHasErrors('name');
    }
}
