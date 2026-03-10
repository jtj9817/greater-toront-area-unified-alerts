<?php

namespace App\Http\Requests\Notifications;

use App\Models\SavedPlace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavedPlaceUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => strip_tags(trim($this->input('name'))),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return SavedPlace::validationRules(partial: true);
    }
}
