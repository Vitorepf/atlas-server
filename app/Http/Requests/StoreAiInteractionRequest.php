<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

        if ($this->has('rich_input_payload') && is_string($this->input('rich_input_payload'))) {
            $decoded = json_decode((string) $this->input('rich_input_payload'), true);
            $this->merge([
                'rich_input_payload' => json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null,
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
            'provider' => ['nullable', 'string', 'in:claude_cli,codex_cli,gemini_cli,hermes_cli,minimax_m27_cli,claude_codex'],
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
            'rich_input_payload' => ['nullable', 'array'],
            'rich_input_payload.schema_version' => ['nullable', 'string', 'max:80'],
            'rich_input_payload.uploaded_image_ids' => ['nullable', 'array', 'max:8'],
            'rich_input_payload.uploaded_image_ids.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input_payload.uploaded_document_ids' => ['nullable', 'array', 'max:4'],
            'rich_input_payload.uploaded_document_ids.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input_payload.url_attachments' => ['nullable', 'array', 'max:16'],
            'rich_input_payload.url_attachments.*' => ['array'],
            'rich_input_payload.url_attachments.*.url' => ['required_with:rich_input_payload.url_attachments.*', 'string', 'max:2048'],
            'rich_input_payload.url_attachments.*.kind' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.url_attachments.*.title' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.url_attachments.*.author' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.url_attachments.*.duration_sec' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.url_attachments.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'rich_input_payload.url_attachments.*.ref_id' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.text_blocks' => ['nullable', 'array', 'max:8'],
            'rich_input_payload.text_blocks.*' => ['array'],
            'rich_input_payload.text_blocks.*.file_name' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.text_blocks.*.mime_type' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.text_blocks.*.language' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.text_blocks.*.content' => ['required_with:rich_input_payload.text_blocks.*', 'string', 'max:200000'],
            'rich_input_payload.text_blocks.*.page_count' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.source_manifest' => ['nullable', 'array', 'max:36'],
            'rich_input_payload.source_manifest.*' => ['array'],
            'rich_input_payload.source_manifest.*.id' => ['nullable', 'string', 'max:160'],
            'rich_input_payload.source_manifest.*.kind' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.source_manifest.*.file_name' => ['nullable', 'string', 'max:2048'],
            'rich_input_payload.source_manifest.*.mime_type' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.source_manifest.*.size' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.source_manifest.*.uploaded_id' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.source_manifest.*.source_hash' => ['nullable', 'string', 'max:128'],
            'rich_input_payload.source_manifest.*.source' => ['nullable', 'string', 'max:80'],
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

    /**
     * YouTube canonical capability: when a `rich_input_payload.url_attachments[]`
     * entry has `kind='youtube'` it MUST carry a valid 11-char video id in
     * `ref_id`. Surfaces (mobile/desktop) compute this via
     * `@atlas/rich-input-canon` — a missing ref_id means a bad payload, not
     * a missing field. See docs/rich-input/youtube-canon.md.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $attachments = $this->input('rich_input_payload.url_attachments');
            if (! is_array($attachments)) {
                return;
            }

            foreach ($attachments as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $kind = strtolower((string) ($entry['kind'] ?? ''));
                if ($kind !== 'youtube') {
                    continue;
                }
                $refId = (string) ($entry['ref_id'] ?? '');
                if ($refId === '' || preg_match('/^[A-Za-z0-9_-]{11}$/', $refId) !== 1) {
                    $validator->errors()->add(
                        "rich_input_payload.url_attachments.{$index}.ref_id",
                        "Quando 'kind' = 'youtube', 'ref_id' precisa ser o videoId canonico de 11 caracteres."
                    );
                }
            }
        });
    }
}
