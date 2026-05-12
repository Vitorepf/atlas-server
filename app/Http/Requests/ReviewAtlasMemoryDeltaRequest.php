<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAtlasMemoryDeltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['accept', 'reject'])],
            'reviewed_by' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
