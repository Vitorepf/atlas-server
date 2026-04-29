<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexDigitalCategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current' => ['sometimes', 'boolean'],
            'category_class' => ['sometimes', 'integer', 'between:1,10'],
            'source_identifier' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ];
    }
}
