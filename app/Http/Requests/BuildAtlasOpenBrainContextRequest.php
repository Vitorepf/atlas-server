<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BuildAtlasOpenBrainContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'objective' => ['required', 'string', 'max:4000'],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'task_type' => ['nullable', Rule::in(['direct', 'dev', 'debug', 'review', 'research', 'decision', 'memory'])],
            'desired_mode' => ['nullable', 'string', 'max:80'],
            'agent' => ['nullable', 'string', 'max:120'],
            'intent' => ['nullable', 'string', 'max:120'],
            'requester' => ['nullable', 'string', 'max:120'],
            'include_prompt' => ['nullable', 'boolean'],
            'payload' => ['nullable', 'array'],
            'payload.project_id' => ['nullable', 'uuid'],
            'payload.task_id' => ['nullable', 'uuid'],
            'payload.engineering_run_id' => ['nullable', 'uuid'],
            'payload.run_id' => ['nullable', 'uuid'],
            'payload.session_id' => ['nullable', 'uuid'],
            'payload.user_id' => ['nullable', 'string', 'max:180'],
            'options' => ['nullable', 'array'],
            'options.include_memory_registry' => ['nullable', 'boolean'],
            'options.include_verbatim_recall' => ['nullable', 'boolean'],
            'options.include_semantic_context' => ['nullable', 'boolean'],
            'options.memory_recall_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'options.memory_recall_budget_chars' => ['nullable', 'integer', 'min:120', 'max:12000'],
            'options.memory_recall_item_chars' => ['nullable', 'integer', 'min:80', 'max:3000'],
        ];
    }
}
