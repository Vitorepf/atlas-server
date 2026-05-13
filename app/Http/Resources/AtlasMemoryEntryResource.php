<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class AtlasMemoryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'memory_type' => $this->memory_type,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'engineering_run_id' => $this->engineering_run_id,
            'trace_id' => $this->trace_id,
            'session_id' => $this->session_id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'redacted_title' => $this->redacted_title,
            'body' => $this->body,
            'redacted_body' => $this->redacted_body,
            'summary' => $this->summary,
            'redacted_summary' => $this->redacted_summary,
            'importance' => $this->importance,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'privacy_class' => $this->privacy_class,
            'external_ai_allowed' => $this->external_ai_allowed,
            'redaction_status' => $this->redaction_status,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_label' => $this->source_label,
            'status' => $this->status,
            'tags' => Metadata::listForResponse($this->tags),
            'metadata' => Metadata::forResponse($this->metadata),
            'safety' => $this->safetySummary(),
            'content_hash' => $this->content_hash,
            'recorded_at' => $this->recorded_at?->toJSON(),
            'last_used_at' => $this->last_used_at?->toJSON(),
            'archived_at' => $this->archived_at?->toJSON(),
            'governance_checked_at' => $this->governance_checked_at?->toJSON(),
            'privacy_reviewed_at' => $this->privacy_reviewed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function safetySummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $providerExportAllowed = $this->external_ai_allowed === true
            && $this->privacy_class !== 'secret'
            && $this->redaction_status !== 'blocked';
        $promotionLineage = $this->promotionLineageSafety($metadata);

        return [
            'schema_version' => 'atlas.memory_entry.safety.v1',
            'memory_eligible' => $this->status === 'active',
            'context_eligible' => $this->status === 'active' && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $this->status === 'active' && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => $this->privacy_class,
            'redaction_status' => $this->redaction_status,
            'content_hash' => $this->content_hash,
            'lineage_schema_version' => 'atlas.memory_entry.lineage_safety.v1',
            'source_type' => $this->source_type,
            'source_id_hash' => $this->hashOrNull($this->source_id),
            'promoted_from' => $promotionLineage['promoted_from'],
            'promotion_delta_id_hash' => $promotionLineage['delta_id_hash'],
            'promotion_evidence_count' => $promotionLineage['evidence_count'],
            'promotion_requires_confirmation' => $promotionLineage['requires_confirmation'],
            'promotion_valid_from' => $promotionLineage['valid_from'],
            'promotion_valid_until' => $promotionLineage['valid_until'],
            'promotion_freshness_status' => $promotionLineage['freshness_status'],
            'raw_lineage_content_exposed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function promotionLineageSafety(array $metadata): array
    {
        $validUntil = $this->stringOrNull($metadata['valid_until'] ?? null);

        return [
            'promoted_from' => $this->stringOrNull($metadata['promoted_from'] ?? null),
            'delta_id_hash' => $this->hashOrNull($metadata['delta_id'] ?? null),
            'evidence_count' => is_array($metadata['evidence'] ?? null) ? count($metadata['evidence']) : 0,
            'requires_confirmation' => array_key_exists('requires_confirmation', $metadata)
                ? (bool) $metadata['requires_confirmation']
                : null,
            'valid_from' => $this->stringOrNull($metadata['valid_from'] ?? null),
            'valid_until' => $validUntil,
            'freshness_status' => $this->freshnessStatus($validUntil),
        ];
    }

    private function freshnessStatus(?string $validUntil): string
    {
        if ($validUntil === null) {
            return 'not_declared';
        }

        try {
            return Carbon::parse($validUntil)->isPast() ? 'expired' : 'active';
        } catch (\Throwable) {
            return 'invalid_valid_until';
        }
    }

    private function hashOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return hash('sha256', trim((string) $value));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
