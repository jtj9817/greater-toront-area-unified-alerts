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

        $this->merge([
            'content' => trim((string) $this->input('content')),
        ]);
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
