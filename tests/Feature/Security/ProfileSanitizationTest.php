<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('profile name is sanitized on update', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => '<script>alert("xss")</script> New Name',
            'email' => $user->email,
        ])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    // Expecting sanitization to remove script tags
    expect($user->name)->toBe('alert("xss") New Name');
});

test('profile name with tags is sanitized but keeps content', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => '<script>alert("xss")</script>',
            'email' => $user->email,
        ])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    // strip_tags('<script>alert("xss")</script>') is 'alert("xss")' because the content inside script tags is NOT stripped by strip_tags in some PHP versions?
    // Wait, strip_tags DOES remove script tags but keeps content?
    // Let's verify what strip_tags does.
    // strip_tags('<script>alert("xss")</script>') -> 'alert("xss")'

    expect($user->name)->toBe('alert("xss")');
});
