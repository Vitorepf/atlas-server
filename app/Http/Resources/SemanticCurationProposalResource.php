<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SemanticCurationProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_refs' => Metadata::forResponse($this->source_refs),
            'proposed_note_type' => $this->proposed_note_type,
            'proposed_title' => $this->proposed_title,
            'proposed_summary' => $this->proposed_summary,
            'proposed_path' => $this->proposed_path,
            'proposed_frontmatter' => Metadata::forResponse($this->proposed_frontmatter),
            'proposed_body' => $this->proposed_body,
            'score' => $this->score,
            'reason' => $this->reason,
            'status' => $this->status,
            'shown_at' => $this->shown_at?->toJSON(),
            'resolved_at' => $this->resolved_at?->toJSON(),
            'metadata' => Metadata::forResponse($this->metadata),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
