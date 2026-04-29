<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMetadata;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDigitalCategoryMappingRequest extends FormRequest
{
    use NormalizesMetadata;

    protected function prepareForValidation(): void
    {
        $this->normalizeMetadata();

        if (! $this->has('metadata')) {
            $this->merge(['metadata' => []]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_identifier' => ['required', 'string', 'max:512'],
            'source_name' => ['required', 'string', 'max:512'],
            'source_kind' => ['required', Rule::in(['app', 'domain', 'url', 'project', 'category', 'unknown'])],
            'category_class' => ['required', 'integer', 'between:1,10'],
            'category_label' => ['required', 'string', 'max:128'],
            'intentionality' => ['required', Rule::in(['intentional', 'default', 'mixed', 'unknown'])],
            'classified_by' => ['required', Rule::in(['operator', 'system_suggestion', 'import'])],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'valid_from' => ['sometimes', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from'],
            'notes' => ['nullable', 'string', 'max:2048'],
            'metadata' => ['array'],
        ];
    }
}
