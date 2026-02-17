<?php

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

test('password defaults enforce complexity in non-production environments', function () {
    // In testing environment, app()->isProduction() is false.
    // The current implementation returns a default Password object (min: 8) with NO other rules.

    // We want to ensure that even in non-production, complexity rules are enforced.
    // Specifically: mixedCase, letters, numbers.
    // Uncompromised is excluded (which is fine for dev/test).

    $rule = Password::default();

    // Test case 1: Weak password (length ok, but no complexity)
    $weakPassword = 'password';
    $validator = Validator::make(['password' => $weakPassword], [
        'password' => $rule,
    ]);

    // This should fail if complexity is enforced.
    expect($validator->fails())
        ->toBeTrue('Password "password" should fail validation due to lack of complexity (mixed case, numbers)');

    // Test case 2: Stronger password (length ok, complexity ok)
    $strongerPassword = 'Password1';
    $validatorStrong = Validator::make(['password' => $strongerPassword], [
        'password' => $rule,
    ]);

    expect($validatorStrong->passes())
        ->toBeTrue('Complex password "Password1" should pass validation');
});
