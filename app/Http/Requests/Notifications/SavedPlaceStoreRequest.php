<?php

namespace App\Http\Requests\Notifications;

use App\Models\SavedPlace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavedPlaceStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return SavedPlace::validationRules();
    }
}
