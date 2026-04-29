<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiInteractionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'input_text' => ['required', 'string', 'max:50000'],
            'client_id' => ['nullable', 'uuid'],
            'agent_slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,claude_codex'],
            'model' => ['nullable', 'string', 'max:120'],
            'source_type' => ['nullable', 'string', 'in:manual,app,capture,semantic_memory,scheduled,system'],
            'source_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', 'string', 'in:interaction,curation,analysis,council,skill_test,manual'],
            'priority' => ['nullable', 'integer', 'between:0,100'],
            'max_attempts' => ['nullable', 'integer', 'between:1,5'],
            'timeout_seconds' => ['nullable', 'integer', 'between:15,1800'],
            'include_semantic_context' => ['nullable', 'boolean'],
            'context_note_limit' => ['nullable', 'integer', 'between:0,20'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
