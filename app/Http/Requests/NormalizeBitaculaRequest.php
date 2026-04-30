<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NormalizeBitaculaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
