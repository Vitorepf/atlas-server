<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAtlasMemoryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(AtlasMemoryEntry::STATUSES)],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
