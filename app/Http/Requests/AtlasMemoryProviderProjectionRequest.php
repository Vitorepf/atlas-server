<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtlasMemoryProviderProjectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('target')) {
            $this->merge(['target' => 'all']);
        }
    }

    public function rules(): array
    {
        return [
            'target' => ['nullable', 'string', Rule::in(['claude', 'agents', 'all'])],
            'workspace' => ['nullable', 'string', 'max:2048'],
            'max_lines' => ['nullable', 'integer', 'min:20', 'max:240'],
            'memory_limit' => ['nullable', 'integer', 'min:1', 'max:80'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
