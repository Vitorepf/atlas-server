<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecallAtlasMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:2000'],
            'context' => ['nullable', 'array'],
            'context.workspace' => ['nullable', 'string', 'max:1000'],
            'context.workspace_path' => ['nullable', 'string', 'max:1000'],
            'context.project_id' => ['nullable', 'uuid'],
            'context.task_id' => ['nullable', 'uuid'],
            'context.engineering_run_id' => ['nullable', 'uuid'],
            'context.run_id' => ['nullable', 'uuid'],
            'context.session_id' => ['nullable', 'uuid'],
            'context.user_id' => ['nullable', 'string', 'max:180'],
            'filters' => ['nullable', 'array'],
            'filters.types' => ['nullable', 'array'],
            'filters.types.*' => ['string', Rule::in(['decision', 'preference', 'feedback', 'technical_context', 'issue', 'resolution', 'benchmark_observation', 'harness_learning'])],
            'filters.memory_type' => ['nullable'],
            'filters.privacy_class' => ['nullable', Rule::in(['normal', 'private', 'sensitive', 'secret'])],
            'filters.semantic' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'options.registry_limit' => ['nullable', 'integer', 'min:0', 'max:100'],
            'options.verbatim_limit' => ['nullable', 'integer', 'min:0', 'max:50'],
            'options.semantic_limit' => ['nullable', 'integer', 'min:0', 'max:50'],
            'options.budget_chars' => ['nullable', 'integer', 'min:120', 'max:12000'],
            'options.item_chars' => ['nullable', 'integer', 'min:80', 'max:3000'],
            'options.include_registry' => ['nullable', 'boolean'],
            'options.include_verbatim' => ['nullable', 'boolean'],
            'options.include_semantic' => ['nullable', 'boolean'],
        ];
    }
}
