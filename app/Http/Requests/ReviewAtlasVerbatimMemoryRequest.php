<?php

namespace App\Http\Requests;

use App\Models\AtlasVerbatimMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAtlasVerbatimMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::PRIVACY_CLASSES)],
            'external_ai_allowed' => ['nullable', 'boolean'],
            'redacted_text' => ['nullable', 'string', 'max:100000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::STATUSES)],
            're_redact' => ['nullable', 'boolean'],
            'reviewed_by' => ['nullable', 'string', 'max:120'],
            'review_action' => ['nullable', 'string', 'max:80'],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
