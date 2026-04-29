<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBehaviorRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        foreach (['source_capture_ids', 'activation_rules'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $decoded = json_decode($this->input($key), true);
                $this->merge([$key => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
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
            'name' => ['sometimes', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:180'],
            'category' => ['sometimes', Rule::in(['bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'outro'])],
            'input_type' => ['sometimes', Rule::in(['yes_no', 'scale_1_5', 'count_int', 'text_short'])],
            'question_text' => ['sometimes', 'string', 'max:240'],
            'default_value' => ['sometimes', 'nullable', 'string', 'max:80'],
            'created_by' => ['sometimes', Rule::in(['operator', 'ai_suggestion', 'import'])],
            'source_capture_ids' => ['sometimes', 'array'],
            'activation_rules' => ['sometimes', 'array'],
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
