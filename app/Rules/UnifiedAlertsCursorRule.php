<?php

namespace App\Rules;

use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UnifiedAlertsCursorRule implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail("The {$attribute} must be a string.");

            return;
        }

        try {
            UnifiedAlertsCursor::decode($value);
        } catch (\Throwable) {
            $fail("The {$attribute} is invalid.");
        }
    }
}

