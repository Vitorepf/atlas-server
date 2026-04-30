<?php

namespace App\Http\Requests;

use App\Support\BehaviorCategories;
use App\Support\BehaviorLifecycle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBehaviorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('category')) {
            $this->merge(['category' => BehaviorCategories::canonicalize((string) $this->input('category'))]);
        }

        if ($this->filled('lifecycle_status')) {
            $this->merge(['lifecycle_status' => BehaviorLifecycle::canonicalize((string) $this->input('lifecycle_status'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'category' => ['sometimes', Rule::in(BehaviorCategories::allowed())],
            'lifecycle_status' => ['sometimes', Rule::in(BehaviorLifecycle::allowed())],
            'active' => ['sometimes', 'boolean'],
            'briefing' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
