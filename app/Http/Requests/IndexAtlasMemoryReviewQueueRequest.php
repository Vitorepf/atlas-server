<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAtlasMemoryReviewQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $areas = $this->input('areas', $this->input('area'));
        if (is_string($areas)) {
            $areas = [$areas];
        }
        if (is_array($areas)) {
            $this->merge(['areas' => array_values($areas)]);
        }

        if ($this->filled('privacy')) {
            $this->merge(['privacy_class' => $this->input('privacy')]);
        }
        if ($this->filled('run_id')) {
            $this->merge(['engineering_run_id' => $this->input('run_id')]);
        }

        $relationTypes = $this->input('relation_types', $this->input('relation_type'));
        if (is_string($relationTypes)) {
            $relationTypes = [$relationTypes];
        }
        if (is_array($relationTypes)) {
            $this->merge(['relation_types' => array_values($relationTypes)]);
        }
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'areas' => ['nullable', 'array'],
            'areas.*' => ['string', Rule::in([
                'memory',
                'registry',
                'privacy',
                'memory_privacy',
                'verbatim',
                'verbatim_privacy',
                'relation',
                'relations',
            ])],
            'scope_type' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::SCOPES)],
            'scope_id' => ['nullable', 'string', 'max:120'],
            'project_id' => ['nullable', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'engineering_run_id' => ['nullable', 'uuid'],
            'privacy_class' => ['nullable', 'string', Rule::in(AtlasMemoryEntry::PRIVACY_CLASSES)],
            'relation_status' => ['nullable', 'string', Rule::in(AtlasMemoryEntryRelation::STATUSES)],
            'relation_types' => ['nullable', 'array'],
            'relation_types.*' => ['string', Rule::in(AtlasMemoryEntryRelation::TYPES)],
            'include_unreviewed' => ['nullable', 'boolean'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
