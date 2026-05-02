<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAtlasMemoryPrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::PRIVACY_CLASSES)],
            'external_ai_allowed' => ['nullable', 'boolean'],
            'redacted_title' => ['nullable', 'string', 'max:180'],
            'redacted_body' => ['nullable', 'string', 'max:20000'],
            'redacted_summary' => ['nullable', 'string', 'max:2000'],
            'reviewed_by' => ['nullable', 'string', 'max:120'],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
