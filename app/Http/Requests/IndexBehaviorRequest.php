<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBehaviorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'category' => ['sometimes', Rule::in(['bebida', 'alimentacao', 'conflito', 'sono', 'treino', 'suplemento', 'social', 'trabalho', 'outro'])],
            'active' => ['sometimes', 'boolean'],
            'briefing' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
