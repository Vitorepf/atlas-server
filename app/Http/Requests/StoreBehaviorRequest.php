<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreBehaviorRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();
        $this->normalizeJsonArray('source_capture_ids', []);
        $this->normalizeJsonArray('activation_rules', []);

        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug((string) $this->input('name'), '_')]);
        }

        if (! $this->filled('question_text') && $this->filled('name')) {
            $this->merge(['question_text' => sprintf('%s aconteceu ontem?', trim((string) $this->input('name')))]);
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
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:180'],
            'category' => ['required', Rule::in(['bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'outro'])],
            'input_type' => ['required', Rule::in(['yes_no', 'scale_1_5', 'count_int', 'text_short'])],
            'question_text' => ['required', 'string', 'max:240'],
            'default_value' => ['nullable', 'string', 'max:80'],
            'created_by' => ['nullable', Rule::in(['operator', 'ai_suggestion', 'import'])],
            'source_capture_ids' => ['array'],
            'activation_rules' => ['array'],
            'show_in_morning_briefing' => ['nullable', 'boolean'],
            'priority_score' => ['nullable', 'integer'],
            'streak_yes' => ['nullable', 'integer', 'min:0'],
            'streak_no' => ['nullable', 'integer', 'min:0'],
            'total_yes_count' => ['nullable', 'integer', 'min:0'],
            'total_no_count' => ['nullable', 'integer', 'min:0'],
            'relational_privacy' => ['nullable', 'boolean'],
            'activated_at' => ['nullable', 'date'],
            'archived_at' => ['nullable', 'date'],
            'promoted_to_object_type' => ['nullable', 'string', 'max:128'],
            'promoted_to_object_id' => ['nullable', 'uuid'],
            'metadata' => ['array'],
        ];
    }

    protected function normalizeJsonArray(string $key, array $fallback): void
    {
        if (! $this->has($key)) {
            $this->merge([$key => $fallback]);

            return;
        }

        if (is_string($this->input($key))) {
            $decoded = json_decode($this->input($key), true);
            $this->merge([$key => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null]);
        }
    }
}
