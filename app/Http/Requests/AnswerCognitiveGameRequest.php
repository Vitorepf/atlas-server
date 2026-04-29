<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnswerCognitiveGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operator_answer' => ['required', 'string', 'max:8000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }
}
