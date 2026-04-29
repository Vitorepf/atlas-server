<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPassiveSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'source' => ['sometimes', Rule::in(['healthkit', 'rize'])],
            'signal_type' => ['sometimes', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
