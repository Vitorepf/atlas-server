<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAtlasVerbatimMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $types = $this->input('types', $this->input('verbatim_type', $this->input('type')));
        if (is_string($types)) {
            $types = [$types];
        }
        if (is_array($types)) {
            $this->merge(['types' => array_values($types)]);
        }

        $tags = $this->input('tags', $this->input('tag'));
        if (is_string($tags)) {
            $tags = [$tags];
        }
        if (is_array($tags)) {
            $this->merge(['tags' => array_values($tags)]);
        }
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(AtlasVerbatimMemory::TYPES)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'trace_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::PRIVACY_CLASSES)],
            'status' => ['nullable', 'string', Rule::in(AtlasVerbatimMemory::STATUSES)],
            'include_inactive' => ['nullable', 'boolean'],
            'include_verbatim' => ['nullable', 'boolean'],
        ];
    }
}
