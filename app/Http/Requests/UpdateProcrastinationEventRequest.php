<?php

namespace App\Http\Requests;

class UpdateProcrastinationEventRequest extends StoreProcrastinationEventRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['mission_context', 'physiological_state', 'subjective_state', 'digital_context', 'metadata'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $decoded = json_decode($this->input($field), true);
                $this->merge([$field => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
            }
        }
    }

    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules): array => array_values(array_merge(['sometimes'], array_filter($rules, fn (mixed $rule): bool => $rule !== 'required'))))
            ->all();
    }
}
