<?php

namespace App\Http\Requests\Settings;

use App\Models\NotificationPreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferenceUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $input = [];

        // Sentinel: Sanitize geofence names to prevent Stored XSS
        if ($this->has('geofences') && is_array($this->input('geofences'))) {
            $input['geofences'] = array_map(function ($geofence) {
                if (is_array($geofence) && isset($geofence['name']) && is_string($geofence['name'])) {
                    $geofence['name'] = strip_tags(trim($geofence['name']));
                }

                return $geofence;
            }, $this->input('geofences'));
        }

        // Sentinel: Sanitize subscriptions to prevent Stored XSS
        if ($this->has('subscriptions') && is_array($this->input('subscriptions'))) {
            $input['subscriptions'] = array_map(function ($subscription) {
                return is_string($subscription) ? strip_tags(trim($subscription)) : $subscription;
            }, $this->input('subscriptions'));
        }

        // Sentinel: Sanitize legacy subscribed_routes to prevent Stored XSS
        if ($this->has('subscribed_routes') && is_array($this->input('subscribed_routes'))) {
            $input['subscribed_routes'] = array_map(function ($subscription) {
                return is_string($subscription) ? strip_tags(trim($subscription)) : $subscription;
            }, $this->input('subscribed_routes'));
        }

        if (! empty($input)) {
            $this->merge($input);
        }
    }

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
