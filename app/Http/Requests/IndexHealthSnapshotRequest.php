<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexHealthSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'date'],
            'source' => ['sometimes', Rule::in(['atlas_app', 'server', 'import'])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'uuid'],
        ];
    }
}
