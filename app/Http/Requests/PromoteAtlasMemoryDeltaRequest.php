<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteAtlasMemoryDeltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'memory_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::TYPES)],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'importance' => ['nullable', 'integer', 'min:1', 'max:5'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'metadata' => ['nullable', 'array'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
