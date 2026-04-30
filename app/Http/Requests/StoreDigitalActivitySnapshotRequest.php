<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use App\Services\Digital\DigitalActivityQuality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDigitalActivitySnapshotRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['focus_mode_active_min', 'category_breakdown', 'source_breakdown', 'raw_rize_data', 'raw_screentime_data', 'metadata'] as $field) {
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
            'source' => ['required', Rule::in(['atlas_server', 'rize', 'screentime', 'manual', 'import'])],
            'snapshot_date' => ['required', 'date'],
            'snapshot_timezone' => ['required', 'string', 'max:128'],
            'computed_at' => ['required', 'date'],
            'signal_count' => ['required', 'integer', 'min:0'],
            'total_screen_time_min' => ['nullable', 'integer', 'min:0'],
            'pickups_count' => ['nullable', 'integer', 'min:0'],
            'first_offensive_use_min_after_wake' => ['nullable', 'integer', 'min:0'],
            'deep_work_sessions_count' => ['nullable', 'integer', 'min:0'],
            'deep_work_total_min' => ['nullable', 'integer', 'min:0'],
            'notifications_received' => ['nullable', 'integer', 'min:0'],
            'notifications_actioned' => ['nullable', 'integer', 'min:0'],
            'curated_input_min' => ['nullable', 'integer', 'min:0'],
            'algorithmic_input_min' => ['nullable', 'integer', 'min:0'],
            'intentional_entertainment_min' => ['nullable', 'integer', 'min:0'],
            'default_entertainment_min' => ['nullable', 'integer', 'min:0'],
            'communication_primary_min' => ['nullable', 'integer', 'min:0'],
            'communication_shallow_min' => ['nullable', 'integer', 'min:0'],
            'market_min' => ['nullable', 'integer', 'min:0'],
            'focus_mode_active_min' => ['array'],
            'category_breakdown' => ['array'],
            'source_breakdown' => ['array'],
            'raw_rize_data' => ['array'],
            'raw_screentime_data' => ['array'],
            'metadata' => ['array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = app(DigitalActivityQuality::class)->consistencyErrors($this->all());

                foreach ($errors as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
