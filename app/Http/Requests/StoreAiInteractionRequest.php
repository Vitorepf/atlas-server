<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAiInteractionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('payload') && is_string($this->input('payload'))) {
            $decoded = json_decode((string) $this->input('payload'), true);
            $this->merge([
                'payload' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'input_text' => ['required', 'string', 'max:50000'],
            'client_id' => ['nullable', 'uuid'],
            'thread_id' => ['nullable', 'uuid', 'exists:ai_threads,id'],
            'session_id' => ['nullable', 'uuid', 'exists:ai_sessions,id'],
            'new_thread' => ['nullable', 'boolean'],
            'allow_implicit_thread_continuation' => ['nullable', 'boolean'],
            'agent_slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,gemini_cli,claude_codex'],
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
            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => [
                'file',
                'max:20480',
                'mimetypes:image/png,image/jpeg,image/webp,image/gif',
            ],
            'uploaded_images' => ['nullable', 'array', 'max:8'],
            'uploaded_images.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'documents' => ['nullable', 'array', 'max:4'],
            'documents.*' => [
                'file',
                'max:20480',
            ],
            'uploaded_documents' => ['nullable', 'array', 'max:4'],
            'uploaded_documents.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
