<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'state' => ['sometimes', Rule::in(['focused', 'disperse', 'blocked', 'pause'])],
            'energy_level' => ['sometimes', 'integer', 'between:1,5'],
            'mood_level' => ['sometimes', 'integer', 'between:1,5'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
