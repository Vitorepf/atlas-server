<?php

namespace App\Http\Requests;

use App\Models\AtlasVerbatimMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAtlasVerbatimMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(AtlasVerbatimMemory::STATUSES)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
