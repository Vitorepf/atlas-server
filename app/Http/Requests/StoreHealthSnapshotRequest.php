<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHealthSnapshotRequest extends FormRequest
{
    use NormalizesMetadata;

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

        foreach (['metrics', 'readiness', 'sleep', 'recovery', 'load', 'subjective', 'body', 'metadata'] as $key) {
            if (! $this->has($key)) {
                $this->merge([$key => []]);
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
            'source' => ['required', Rule::in(['atlas_app', 'server', 'import'])],
            'snapshot_date' => ['required', 'date'],
            'snapshot_timezone' => ['required', 'string', 'max:128'],
            'computed_at' => ['required', 'date'],
            'signal_count' => ['required', 'integer', 'min:0'],
            ...$this->metricRules(false),
            'metrics' => ['array'],
            'readiness' => ['array'],
            'sleep' => ['array'],
            'recovery' => ['array'],
            'load' => ['array'],
            'subjective' => ['array'],
            'body' => ['array'],
            'metadata' => ['array'],
        ];
    }

    protected function metricRules(bool $partial): array
    {
        $presence = $partial ? 'sometimes' : 'nullable';

        return [
            'readiness_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'current_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'body_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'mind_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'drive_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'sleep_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'autonomic_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'load_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'subjective_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'stability_score' => [$presence, 'nullable', 'integer', 'between:0,100'],
            'confidence' => [$presence, 'nullable', 'numeric', 'between:0,100'],
            'sleep_duration_hours' => [$presence, 'nullable', 'numeric'],
            'sleep_efficiency' => [$presence, 'nullable', 'numeric'],
            'hrv_ms' => [$presence, 'nullable', 'numeric'],
            'resting_heart_rate_bpm' => [$presence, 'nullable', 'numeric'],
            'respiratory_rate' => [$presence, 'nullable', 'numeric'],
            'wrist_temperature_c' => [$presence, 'nullable', 'numeric'],
            'active_energy_kcal' => [$presence, 'nullable', 'numeric'],
            'basal_energy_kcal' => [$presence, 'nullable', 'numeric'],
            'exercise_minutes' => [$presence, 'nullable', 'numeric'],
            'stand_minutes' => [$presence, 'nullable', 'numeric'],
            'steps' => [$presence, 'nullable', 'numeric'],
            'walking_running_distance_m' => [$presence, 'nullable', 'numeric'],
            'vo2max' => [$presence, 'nullable', 'numeric'],
            'body_mass_kg' => [$presence, 'nullable', 'numeric'],
            'body_fat_percentage' => [$presence, 'nullable', 'numeric'],
            'lean_body_mass_kg' => [$presence, 'nullable', 'numeric'],
            'muscle_mass_percentage' => [$presence, 'nullable', 'numeric'],
            'body_mass_index' => [$presence, 'nullable', 'numeric'],
            'waist_circumference_cm' => [$presence, 'nullable', 'numeric'],
            'energy_level' => [$presence, 'nullable', 'integer', 'between:1,5'],
            'mood_level' => [$presence, 'nullable', 'integer', 'between:1,5'],
            'state' => [$presence, 'nullable', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
        ];
    }
}
