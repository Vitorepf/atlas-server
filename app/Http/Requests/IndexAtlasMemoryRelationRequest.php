<?php

namespace App\Http\Requests;

use App\Models\AtlasMemoryEntryRelation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAtlasMemoryRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $types = $this->input('types', $this->input('relation_type', $this->input('type')));
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
            'types.*' => ['string', Rule::in(AtlasMemoryEntryRelation::TYPES)],
            'status' => ['nullable', 'string', Rule::in(AtlasMemoryEntryRelation::STATUSES)],
            'source_memory_entry_id' => ['nullable', 'uuid'],
            'target_memory_entry_id' => ['nullable', 'uuid'],
        ];
    }
}
