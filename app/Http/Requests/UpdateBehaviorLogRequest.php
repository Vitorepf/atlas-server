<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBehaviorLogRequest extends FormRequest
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
            'behavior_client_id' => ['sometimes', 'uuid'],
            'log_date' => ['sometimes', 'date'],
            'value' => ['sometimes', 'string', 'max:512'],
            'numeric_value' => ['sometimes', 'nullable', 'numeric'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'recorded_at' => ['sometimes', 'date'],
            'recorded_timezone' => ['sometimes', 'string', 'max:128'],
            'source' => ['sometimes', Rule::in(['morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import'])],
            'source_capture_id' => ['sometimes', 'nullable', 'uuid', 'exists:captures,id'],
            'auto_marked' => ['sometimes', 'boolean'],
            'confirmed_by_operator' => ['sometimes', 'boolean'],
            'reverted_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
