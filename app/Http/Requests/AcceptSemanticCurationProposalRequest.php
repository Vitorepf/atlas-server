<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptSemanticCurationProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['nullable', 'string', 'max:500'],
            'frontmatter_edits' => ['nullable', 'array'],
            'body_edits' => ['nullable', 'string'],
            'promote_to_memory' => ['nullable', 'boolean'],
            'memory_type' => ['nullable', 'string', 'max:40'],
            'scope_type' => ['nullable', 'string', 'max:40'],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'promoted_by' => ['nullable', 'string', 'max:120'],
            'promote_to_verbatim' => ['nullable', 'boolean'],
            'verbatim_type' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::TYPES)],
            'verbatim_text' => ['nullable', 'string', 'max:100000'],
            'verbatim_title' => ['nullable', 'string', 'max:180'],
            'verbatim_summary' => ['nullable', 'string', 'max:2000'],
            'verbatim_privacy_class' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::PRIVACY_CLASSES)],
            'verbatim_external_ai_allowed' => ['nullable', 'boolean'],
            'verbatim_scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'verbatim_scope_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
