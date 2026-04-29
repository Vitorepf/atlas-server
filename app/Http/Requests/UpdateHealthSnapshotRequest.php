<?php

namespace App\Http\Requests;

class UpdateHealthSnapshotRequest extends StoreHealthSnapshotRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['metrics', 'readiness', 'sleep', 'recovery', 'load', 'subjective', 'body'] as $key) {
            if (is_string($this->input($key))) {
                $decoded = json_decode($this->input($key), true);
                $this->merge([
                    $key => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'source' => ['sometimes', 'in:atlas_app,server,import'],
            'snapshot_date' => ['sometimes', 'date'],
            'snapshot_timezone' => ['sometimes', 'string', 'max:128'],
            'computed_at' => ['sometimes', 'date'],
            'signal_count' => ['sometimes', 'integer', 'min:0'],
            ...$this->metricRules(true),
            'metrics' => ['sometimes', 'array'],
            'readiness' => ['sometimes', 'array'],
            'sleep' => ['sometimes', 'array'],
            'recovery' => ['sometimes', 'array'],
            'load' => ['sometimes', 'array'],
            'subjective' => ['sometimes', 'array'],
            'body' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
