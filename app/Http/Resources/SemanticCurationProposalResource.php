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
            'safety' => $this->safetySummary(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $cognitiveQuarantine = is_array($metadata['cognitive_quarantine'] ?? null)
            ? $metadata['cognitive_quarantine']
            : [];
        $immuneAudit = is_array($cognitiveQuarantine['immune_audit'] ?? null)
            ? $cognitiveQuarantine['immune_audit']
            : [];

        return [
            'schema_version' => 'atlas.semantic_curation_proposal.safety.v1',
            'memory_eligible' => $cognitiveQuarantine['memory_eligible'] ?? false,
            'context_eligible' => $cognitiveQuarantine['context_eligible'] ?? false,
            'embedding_allowed' => $cognitiveQuarantine['embedding_allowed'] ?? false,
            'provider_export_allowed' => $cognitiveQuarantine['provider_export_allowed'] ?? false,
            'open_brain_context_allowed' => $cognitiveQuarantine['open_brain_context_allowed'] ?? false,
            'raw_content_exposed' => $cognitiveQuarantine['raw_content_exposed'] ?? false,
            'promotion_status' => $cognitiveQuarantine['promotion_status'] ?? 'unclassified',
            'content_hash' => $cognitiveQuarantine['content_hash'] ?? null,
            'immune_audit_schema_version' => $immuneAudit['schema_version'] ?? null,
            'immune_audit_status' => $immuneAudit['status'] ?? null,
            'immune_audit_hash' => $immuneAudit['audit_hash'] ?? null,
            'immune_noise_gate_status' => data_get($immuneAudit, 'noise_gate.status'),
            'immune_learning_signal_allowed_now' => $immuneAudit['learning_signal_allowed_now'] ?? false,
            'immune_memory_promotion_allowed_now' => $immuneAudit['memory_promotion_allowed_now'] ?? false,
            'immune_context_export_allowed_now' => $immuneAudit['context_export_allowed_now'] ?? false,
            'immune_constellation_promotion_allowed_now' => $immuneAudit['constellation_promotion_allowed_now'] ?? false,
        ];
    }
}
