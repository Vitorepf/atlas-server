<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SemanticActivationFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'usefulness_score' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback_action' => ['nullable', Rule::in(['useful', 'not_useful', 'too_early', 'too_late', 'dismissed'])],
            'operator_feedback' => ['nullable', 'string', 'max:1200'],
        ];
    }
}
