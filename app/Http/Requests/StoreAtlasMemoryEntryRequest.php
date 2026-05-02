<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAtlasMemoryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('memory_type') && $this->has('type')) {
            $this->merge(['memory_type' => $this->input('type')]);
        }
    }

    public function rules(): array
    {
        return [
            'memory_type' => ['required', 'string', Rule::in(AtlasMemoryEntry::TYPES)],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'trace_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::PRIVACY_CLASSES)],
            'external_ai_allowed' => ['nullable', 'boolean'],
            'redacted_title' => ['nullable', 'string', 'max:180'],
            'redacted_body' => ['nullable', 'string', 'max:20000'],
            'redacted_summary' => ['nullable', 'string', 'max:2000'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::STATUSES)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'metadata' => ['nullable', 'array'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
