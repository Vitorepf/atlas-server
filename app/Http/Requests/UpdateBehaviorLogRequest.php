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
        if ($this->has('context') && is_string($this->input('context'))) {
            $decoded = json_decode($this->input('context'), true);
            $this->merge(['context' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
        }
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
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            'occurred_timezone' => ['sometimes', 'nullable', 'string', 'max:128'],
            'quantity_numeric' => ['sometimes', 'nullable', 'numeric'],
            'quantity_unit' => ['sometimes', 'nullable', 'string', 'max:64'],
            'intensity' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'context' => ['sometimes', 'array'],
            'recorded_at' => ['sometimes', 'date'],
            'recorded_timezone' => ['sometimes', 'string', 'max:128'],
            'source' => ['sometimes', Rule::in(['morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import', 'inferred'])],
            'source_capture_id' => ['sometimes', 'nullable', 'uuid', 'exists:captures,id'],
            'auto_marked' => ['sometimes', 'boolean'],
            'confirmed_by_operator' => ['sometimes', 'boolean'],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'inferred_by' => ['sometimes', 'nullable', 'string', 'max:128'],
            'consent_snapshot_id' => ['sometimes', 'nullable', 'uuid'],
            'reverted_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
