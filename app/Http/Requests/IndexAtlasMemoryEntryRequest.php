<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAtlasMemoryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $types = $this->input('types', $this->input('memory_type', $this->input('type')));
        if (is_string($types)) {
            $types = [$types];
        }

        if (is_array($types)) {
            $this->merge(['types' => array_values($types)]);
        }
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(AtlasMemoryEntry::TYPES)],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'trace_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::PRIVACY_CLASSES)],
            'status' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::STATUSES)],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
