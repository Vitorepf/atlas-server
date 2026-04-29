<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCognitiveGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'game_key' => ['nullable', Rule::in(['recall', 'forced_connection', 'adversarial', 'blind_application', 'synthesis'])],
            'note_ids' => ['nullable', 'array', 'max:3'],
            'note_ids.*' => ['uuid'],
        ];
    }
}
