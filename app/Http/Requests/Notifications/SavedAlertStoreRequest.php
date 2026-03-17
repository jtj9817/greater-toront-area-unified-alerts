<?php

namespace App\Http\Requests\Notifications;

use App\Services\Alerts\DTOs\AlertId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavedAlertStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('alert_id') && is_string($this->input('alert_id'))) {
            $this->merge([
                'alert_id' => trim($this->input('alert_id')),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'alert_id' => [
                'required',
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        AlertId::fromString($value);
                    } catch (\InvalidArgumentException) {
                        $fail('The alert_id must be a valid alert identifier in the format {source}:{externalId}.');
                    }
                },
            ],
        ];
    }
}
