<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAtlasMemoryRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(AtlasMemoryEntryRelation::STATUSES)],
            'source_status' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::STATUSES)],
            'target_status' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::STATUSES)],
            'resolution_action' => ['nullable', 'string', 'max:80'],
            'reviewed_by' => ['nullable', 'string', 'max:120'],
            'review_note' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
