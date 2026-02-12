<?php

namespace App\Http\Requests\Settings;

use App\Models\NotificationPreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferenceUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = NotificationPreference::validationRules(partial: true);

        // Backwards-compat: older clients send `subscribed_routes` instead of `subscriptions`.
        $rules['subscribed_routes'] = ['sometimes', 'array'];
        $rules['subscribed_routes.*'] = ['string', 'max:64'];

        return $rules;
    }
}
