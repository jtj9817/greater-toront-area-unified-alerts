<?php

namespace App\Http\Requests\SceneIntel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSceneIntelEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('content')) {
            return;
        }

        $content = $this->input('content');

        if (is_string($content)) {
            $this->merge([
                // Sentinel: Sanitize content to prevent Stored XSS
                'content' => strip_tags(trim($content)),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
