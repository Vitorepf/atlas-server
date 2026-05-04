<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAtlasMemoryQualitySnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workspace' => ['nullable', 'string', 'max:2048'],
            'status' => ['nullable', 'string', 'max:32'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
