<?php

namespace App\Http\Requests;

class UpdateDigitalActivitySnapshotRequest extends StoreDigitalActivitySnapshotRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['focus_mode_active_min', 'category_breakdown', 'source_breakdown', 'raw_rize_data', 'raw_screentime_data', 'metadata'] as $field) {
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
