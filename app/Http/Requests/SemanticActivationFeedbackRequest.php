<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'operator_feedback' => ['nullable', 'string', 'max:1200'],
        ];
    }
}
