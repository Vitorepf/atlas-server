<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAtlasVerbatimMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('verbatim_type') && $this->has('type')) {
            $this->merge(['verbatim_type' => $this->input('type')]);
        }
        if (! $this->has('verbatim_text') && $this->has('verbatim')) {
            $this->merge(['verbatim_text' => $this->input('verbatim')]);
        }
        if (! $this->has('verbatim_text') && $this->has('body')) {
            $this->merge(['verbatim_text' => $this->input('body')]);
        }
    }

    public function rules(): array
    {
        return [
            'verbatim_type' => ['required', 'string', Rule::in(AtlasVerbatimMemory::TYPES)],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'trace_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:180'],
            'verbatim_text' => ['required', 'string', 'max:100000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::PRIVACY_CLASSES)],
            'external_ai_allowed' => ['nullable', 'boolean'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::STATUSES)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'metadata' => ['nullable', 'array'],
            'recorded_at' => ['nullable', 'date'],
            'link_registry' => ['nullable', 'boolean'],
        ];
    }
}
