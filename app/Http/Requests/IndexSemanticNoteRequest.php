<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSemanticNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'array'],
            'type.*' => ['string', Rule::in(['source_note', 'mental_model', 'principle', 'hypothesis', 'practice', 'synthesis', 'decision_identity', 'cognitive_game'])],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(['inbox', 'draft', 'active', 'testing', 'validated', 'archived', 'invalid'])],
            'domain' => ['nullable', 'string', 'max:80'],
            'trigger_signal' => ['nullable', 'string', 'max:120'],
            'since' => ['nullable', 'date'],
        ];
    }
}
