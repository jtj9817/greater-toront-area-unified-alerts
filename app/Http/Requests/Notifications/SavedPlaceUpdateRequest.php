<?php

namespace App\Http\Requests\Notifications;

use App\Models\SavedPlace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavedPlaceUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $name = $this->input('name');
            if (is_string($name)) {
                $this->merge([
                    'name' => strip_tags(trim($name)),
                ]);
            }
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
