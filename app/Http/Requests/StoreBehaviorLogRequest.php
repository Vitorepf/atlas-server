<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBehaviorLogRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'behavior_client_id' => ['required', 'uuid'],
            'log_date' => ['required', 'date'],
            'value' => ['required', 'string', 'max:512'],
            'numeric_value' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:2048'],
            'recorded_at' => ['required', 'date'],
            'recorded_timezone' => ['required', 'string', 'max:128'],
            'source' => ['required', Rule::in(['morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import'])],
            'source_capture_id' => ['nullable', 'uuid', 'exists:captures,id'],
            'auto_marked' => ['nullable', 'boolean'],
            'confirmed_by_operator' => ['nullable', 'boolean'],
            'reverted_at' => ['nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
