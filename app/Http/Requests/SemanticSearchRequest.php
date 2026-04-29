<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SemanticSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:1200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'filters' => ['nullable', 'array'],
            'filters.type' => ['nullable', 'array'],
            'filters.type.*' => ['string', Rule::in(['source_note', 'mental_model', 'principle', 'hypothesis', 'practice', 'synthesis', 'decision_identity', 'cognitive_game'])],
            'filters.status' => ['nullable', 'array'],
            'filters.status.*' => ['string', Rule::in(['inbox', 'draft', 'active', 'testing', 'validated', 'archived', 'invalid'])],
            'filters.domains' => ['nullable', 'array'],
            'filters.domains.*' => ['string', 'max:80'],
            'filters.trigger_signal' => ['nullable', 'string', 'max:120'],
        ];
    }
}
