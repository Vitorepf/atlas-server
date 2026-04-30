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
            'client_id' => ['required', 'uuid'],
            'behavior_client_id' => ['required', 'uuid'],
            'log_date' => ['required', 'date'],
            'value' => ['required', 'string', 'max:512'],
            'numeric_value' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string', 'max:2048'],
            'occurred_at' => ['nullable', 'date'],
            'occurred_timezone' => ['nullable', 'string', 'max:128'],
            'quantity_numeric' => ['nullable', 'numeric'],
            'quantity_unit' => ['nullable', 'string', 'max:64'],
            'intensity' => ['nullable', 'integer', 'between:1,5'],
            'context' => ['sometimes', 'array'],
            'recorded_at' => ['required', 'date'],
            'recorded_timezone' => ['required', 'string', 'max:128'],
            'source' => ['required', Rule::in(['morning_briefing', 'voice_capture', 'manual', 'retroactive', 'import', 'inferred'])],
            'source_capture_id' => ['nullable', 'uuid', 'exists:captures,id'],
            'auto_marked' => ['nullable', 'boolean'],
            'confirmed_by_operator' => ['nullable', 'boolean'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'inferred_by' => ['nullable', 'string', 'max:128'],
            'consent_snapshot_id' => ['nullable', 'uuid'],
            'reverted_at' => ['nullable', 'date'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
