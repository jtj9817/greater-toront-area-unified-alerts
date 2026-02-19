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
        if (! is_string($value)) {
            if ($value === null) {
                return;
            }

            $fail("The {$attribute} must be a string.");

            return;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return;
        }

        try {
            UnifiedAlertsCursor::decode($normalized);
        } catch (\Throwable) {
            $fail("The {$attribute} is invalid.");
        }
    }
}
