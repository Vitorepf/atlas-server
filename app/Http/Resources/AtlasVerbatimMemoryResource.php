<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtlasVerbatimMemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $includeVerbatim = $request->boolean('include_verbatim');

        return [
            'id' => $this->id,
            'memory_entry_id' => $this->memory_entry_id,
            'verbatim_type' => $this->verbatim_type,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'engineering_run_id' => $this->engineering_run_id,
            'trace_id' => $this->trace_id,
            'session_id' => $this->session_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'verbatim_text' => $includeVerbatim ? $this->verbatim_text : null,
            'redacted_text' => $this->redacted_text,
            'summary' => $this->summary,
            'privacy_class' => $this->privacy_class,
            'external_ai_allowed' => $this->external_ai_allowed,
            'redaction_status' => $this->redaction_status,
            'content_hash' => $this->content_hash,
            'redacted_hash' => $this->redacted_hash,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_label' => $this->source_label,
            'status' => $this->status,
            'tags' => Metadata::listForResponse($this->tags),
            'metadata' => Metadata::forResponse($this->metadata),
            'recorded_at' => $this->recorded_at?->toJSON(),
            'last_used_at' => $this->last_used_at?->toJSON(),
            'archived_at' => $this->archived_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
