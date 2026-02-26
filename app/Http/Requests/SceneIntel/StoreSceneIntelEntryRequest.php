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
        if ($this->has('content')) {
            $content = $this->input('content');

            if (is_string($content)) {
                $this->merge([
                    // Sentinel: Sanitize content to prevent Stored XSS
                    'content' => strip_tags(trim($content)),
                ]);
            }
        }

        if ($this->has('metadata')) {
            $metadata = $this->input('metadata');

            if (is_array($metadata)) {
                $this->merge([
                    // Sentinel: Recursively sanitize metadata to prevent Stored XSS
                    'metadata' => $this->sanitizeArray($metadata),
                ]);
            }
        }
    }

    /**
     * Recursively sanitize array values.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private function sanitizeArray(array $input): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeArray($value);
            }

            if (is_string($value)) {
                return strip_tags(trim($value));
            }

            return $value;
        }, $input);
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
