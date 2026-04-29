<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDigitalSessionRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        if ($this->has('raw_payload') && is_string($this->input('raw_payload'))) {
            $decoded = json_decode($this->input('raw_payload'), true);
            $this->merge(['raw_payload' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_event_id' => ['sometimes', 'nullable', 'string', 'max:512'],
            'source_identifier' => ['sometimes', 'string', 'max:512'],
            'source_name' => ['sometimes', 'string', 'max:512'],
            'source_kind' => ['sometimes', Rule::in(['app', 'domain', 'url', 'project', 'category', 'unknown'])],
            'category_class_at_time' => ['sometimes', 'nullable', 'integer', 'between:1,10'],
            'category_label_at_time' => ['sometimes', 'nullable', 'string', 'max:128'],
            'intentionality' => ['sometimes', Rule::in(['intentional', 'default', 'mixed', 'unknown'])],
            'started_at' => ['sometimes', 'date'],
            'ended_at' => ['sometimes', 'date'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0'],
            'recorded_timezone' => ['sometimes', 'string', 'max:128'],
            'focus_mode_active' => ['sometimes', 'nullable', 'string', 'max:128'],
            'project_name' => ['sometimes', 'nullable', 'string', 'max:256'],
            'task_name' => ['sometimes', 'nullable', 'string', 'max:512'],
            'url_domain' => ['sometimes', 'nullable', 'string', 'max:256'],
            'productivity_score' => ['sometimes', 'nullable', 'numeric'],
            'linked_capture_id' => ['sometimes', 'nullable', 'uuid', 'exists:captures,id'],
            'linked_decision_id' => ['sometimes', 'nullable', 'uuid'],
            'raw_payload' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
