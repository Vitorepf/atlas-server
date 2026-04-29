<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDigitalCategoryMappingRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_name' => ['sometimes', 'string', 'max:512'],
            'category_class' => ['sometimes', 'integer', 'between:1,10'],
            'category_label' => ['sometimes', 'string', 'max:128'],
            'intentionality' => ['sometimes', Rule::in(['intentional', 'default', 'mixed', 'unknown'])],
            'classified_by' => ['sometimes', Rule::in(['operator', 'system_suggestion', 'import'])],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
