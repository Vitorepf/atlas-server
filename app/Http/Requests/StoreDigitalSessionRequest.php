<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDigitalSessionRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['raw_payload', 'metadata'] as $field) {
            if (! $this->has($field)) {
                $this->merge([$field => []]);

                continue;
            }

            if (is_string($this->input($field))) {
                $decoded = json_decode($this->input($field), true);
                $this->merge([$field => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
            }
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
            'source' => ['required', Rule::in(['rize', 'screentime', 'manual', 'import'])],
            'source_event_id' => ['nullable', 'string', 'max:512'],
            'source_identifier' => ['required', 'string', 'max:512'],
            'source_name' => ['required', 'string', 'max:512'],
            'source_kind' => ['required', Rule::in(['app', 'domain', 'url', 'project', 'category', 'unknown'])],
            'category_class_at_time' => ['nullable', 'integer', 'between:1,10'],
            'category_label_at_time' => ['nullable', 'string', 'max:128'],
            'intentionality' => ['required', Rule::in(['intentional', 'default', 'mixed', 'unknown'])],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'recorded_timezone' => ['required', 'string', 'max:128'],
            'focus_mode_active' => ['nullable', 'string', 'max:128'],
            'project_name' => ['nullable', 'string', 'max:256'],
            'task_name' => ['nullable', 'string', 'max:512'],
            'url_domain' => ['nullable', 'string', 'max:256'],
            'productivity_score' => ['nullable', 'numeric'],
            'linked_capture_id' => ['nullable', 'uuid', 'exists:captures,id'],
            'linked_decision_id' => ['nullable', 'uuid'],
            'raw_payload' => ['array'],
            'metadata' => ['array'],
        ];
    }
}
