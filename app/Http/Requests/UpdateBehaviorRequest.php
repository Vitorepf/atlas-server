<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use App\Support\BehaviorCategories;
use App\Support\BehaviorLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBehaviorRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['source_capture_ids', 'activation_rules', 'target_outcomes', 'derived_from'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $decoded = json_decode($this->input($key), true);
                $this->merge([$key => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
            }
        }

        if ($this->filled('category')) {
            $this->merge(['category' => BehaviorCategories::canonicalize((string) $this->input('category'))]);
        }

        if ($this->filled('lifecycle_status')) {
            $this->merge(['lifecycle_status' => BehaviorLifecycle::canonicalize((string) $this->input('lifecycle_status'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:180'],
            'category' => ['sometimes', Rule::in(BehaviorCategories::allowed())],
            'input_type' => ['sometimes', Rule::in(['yes_no', 'scale_1_5', 'count_int', 'text_short'])],
            'question_text' => ['sometimes', 'string', 'max:240'],
            'default_value' => ['sometimes', 'nullable', 'string', 'max:80'],
            'parent_factor' => ['sometimes', 'nullable', 'string', 'max:128'],
            'factor_condition' => ['sometimes', 'nullable', 'string', 'max:128'],
            'target_outcomes' => ['sometimes', 'array'],
            'expected_lag' => ['sometimes', 'nullable', 'string', 'max:128'],
            'expected_direction' => ['sometimes', 'nullable', 'string', 'max:32'],
            'granularity_level' => ['sometimes', Rule::in(['binary', 'intensity', 'protocol'])],
            'sensitivity_level' => ['sometimes', Rule::in(['normal', 'sensitive', 'relational', 'medical'])],
            'derived_from' => ['sometimes', 'array'],
            'operator_confirmed' => ['sometimes', 'boolean'],
            'created_by' => ['sometimes', Rule::in(['operator', 'ai_suggestion', 'import'])],
            'source_capture_ids' => ['sometimes', 'array'],
            'activation_rules' => ['sometimes', 'array'],
            'lifecycle_status' => ['sometimes', 'nullable', Rule::in(BehaviorLifecycle::allowed())],
            'paused_until' => ['sometimes', 'nullable', 'date'],
            'last_prompted_at' => ['sometimes', 'nullable', 'date'],
            'prompt_cadence_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:30'],
            'auto_suppress_reason' => ['sometimes', 'nullable', 'string', 'max:128'],
            'show_in_morning_briefing' => ['sometimes', 'boolean'],
            'priority_score' => ['sometimes', 'integer'],
            'streak_yes' => ['sometimes', 'integer', 'min:0'],
            'streak_no' => ['sometimes', 'integer', 'min:0'],
            'total_yes_count' => ['sometimes', 'integer', 'min:0'],
            'total_no_count' => ['sometimes', 'integer', 'min:0'],
            'relational_privacy' => ['sometimes', 'boolean'],
            'activated_at' => ['sometimes', 'nullable', 'date'],
            'archived_at' => ['sometimes', 'nullable', 'date'],
            'promoted_to_object_type' => ['sometimes', 'nullable', 'string', 'max:128'],
            'promoted_to_object_id' => ['sometimes', 'nullable', 'uuid'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
