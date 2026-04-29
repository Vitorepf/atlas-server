<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProcrastinationEventRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['mission_context', 'physiological_state', 'subjective_state', 'digital_context', 'metadata'] as $field) {
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
            'detected_at' => ['required', 'date'],
            'detected_timezone' => ['required', 'string', 'max:128'],
            'duration_min' => ['required', 'integer', 'min:0'],
            'primary_category_class' => ['nullable', 'integer', 'between:1,10'],
            'primary_category_label' => ['nullable', 'string', 'max:128'],
            'mission_active' => ['required', 'boolean'],
            'mission_context' => ['array'],
            'physiological_state' => ['array'],
            'subjective_state' => ['array'],
            'digital_context' => ['array'],
            'rule_version' => ['sometimes', 'string', 'max:64'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'confronted' => ['sometimes', 'boolean'],
            'operator_response' => ['nullable', Rule::in(['accepted', 'dismissed', 'snoozed', 'false_positive'])],
            'metadata' => ['array'],
        ];
    }
}
