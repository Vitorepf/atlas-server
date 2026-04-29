<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemanticNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_key' => $this->note_key,
            'path' => $this->path,
            'title' => $this->title,
            'type' => $this->type,
            'status' => $this->status,
            'confidence' => $this->confidence,
            'maturity' => $this->maturity,
            'domains' => Metadata::forResponse($this->domains),
            'summary' => $this->summary,
            'body_excerpt' => $this->body_excerpt,
            'frontmatter' => Metadata::forResponse($this->frontmatter),
            'when_to_use' => Metadata::forResponse($this->when_to_use),
            'trigger_signals' => Metadata::forResponse($this->trigger_signals),
            'do_not_use_when' => Metadata::forResponse($this->do_not_use_when),
            'postgres_refs' => Metadata::forResponse($this->postgres_refs),
            'content_hash' => $this->content_hash,
            'indexed_at' => $this->indexed_at?->toJSON(),
            'last_seen_at' => $this->last_seen_at?->toJSON(),
            'last_activated_at' => $this->last_activated_at?->toJSON(),
            'last_practiced_at' => $this->last_practiced_at?->toJSON(),
            'activation_count' => $this->activation_count,
            'usefulness_avg' => $this->usefulness_avg,
            'validation_errors' => Metadata::forResponse($this->validation_errors),
            'metadata' => Metadata::forResponse($this->metadata),
            'score' => $this->when(isset($this->score), fn () => (float) $this->score),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }
}
