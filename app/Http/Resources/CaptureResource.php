<?php

namespace App\Http\Resources;

use App\Support\Metadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CaptureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fileExists = $this->content_file_path
            ? Storage::disk('atlas')->exists($this->content_file_path)
            : null;

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'kind' => $this->kind,
            'domain' => $this->domain,
            'content_text' => $this->content_text,
            'content_file_path' => $this->content_file_path,
            'content_file_exists' => $fileExists,
            'content_file_integrity' => $this->fileIntegrity($fileExists),
            'content_duration_ms' => $this->content_duration_ms,
            'content_size_bytes' => $this->content_size_bytes,
            'content_sha256' => $this->content_sha256,
            'content_mime_type' => $this->content_mime_type,
            'transcription_status' => $this->transcription_status,
            'transcription_engine' => $this->transcription_engine,
            'transcription_error' => $this->transcription_error,
            'captured_at' => $this->captured_at?->toJSON(),
            'captured_timezone' => $this->captured_timezone,
            'captured_lat' => $this->captured_lat,
            'captured_lng' => $this->captured_lng,
            'pre_capture_digital_context' => Metadata::forResponse($this->pre_capture_digital_context),
            'metadata' => Metadata::forResponse($this->metadata),
            'capture_safety' => $this->captureSafety($fileExists),
            'review_workflow' => $this->reviewWorkflow(),
            'links' => $this->whenLoaded('links', fn () => CaptureLinkResource::collection($this->links)->resolve()),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
            'deleted_at' => $this->deleted_at?->toJSON(),
        ];
    }

    private function fileIntegrity(?bool $fileExists): string
    {
        if (! $this->content_file_path) {
            return 'not_applicable';
        }

        return $fileExists ? 'available' : 'missing';
    }

    /**
     * @return array<string,mixed>
     */
    private function captureSafety(?bool $fileExists): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $cognitiveQuarantine = is_array($metadata['cognitive_quarantine'] ?? null) ? $metadata['cognitive_quarantine'] : [];
        $immuneAudit = is_array($cognitiveQuarantine['immune_audit'] ?? null) ? $cognitiveQuarantine['immune_audit'] : [];
        $contentIntelligence = is_array($metadata['content_intelligence'] ?? null) ? $metadata['content_intelligence'] : [];
        $contentTextPresent = is_string($this->content_text) && $this->content_text !== '';

        return [
            'schema_version' => 'atlas.capture.resource_safety.v1',
            'authenticated_api_payload' => true,
            'raw_capture' => true,
            'raw_content_present' => $contentTextPresent || $this->content_file_path !== null,
            'raw_text_in_response' => $contentTextPresent,
            'raw_file_path_in_response' => $this->content_file_path !== null,
            'raw_content_provider_export_allowed' => false,
            'raw_content_open_brain_context_allowed' => false,
            'raw_content_embedding_allowed' => false,
            'raw_content_memory_write_allowed' => false,
            'human_review_required_for_promotion' => true,
            'provider_export_allowed' => $cognitiveQuarantine['provider_export_allowed'] ?? false,
            'open_brain_context_allowed' => $cognitiveQuarantine['open_brain_context_allowed'] ?? false,
            'embedding_allowed' => $cognitiveQuarantine['embedding_allowed'] ?? false,
            'memory_eligible' => $cognitiveQuarantine['memory_eligible'] ?? false,
            'context_eligible' => $cognitiveQuarantine['context_eligible'] ?? false,
            'promotion_status' => $cognitiveQuarantine['promotion_status'] ?? 'unclassified',
            'content_hash' => $cognitiveQuarantine['content_hash'] ?? $this->content_sha256,
            'immune_audit_schema_version' => $immuneAudit['schema_version'] ?? null,
            'immune_audit_status' => $immuneAudit['status'] ?? null,
            'immune_audit_hash' => $immuneAudit['audit_hash'] ?? null,
            'immune_master_invariant' => $immuneAudit['master_invariant'] ?? null,
            'immune_noise_gate_status' => data_get($immuneAudit, 'noise_gate.status'),
            'immune_learning_signal_allowed_now' => $immuneAudit['learning_signal_allowed_now'] ?? false,
            'immune_memory_promotion_allowed_now' => $immuneAudit['memory_promotion_allowed_now'] ?? false,
            'immune_context_export_allowed_now' => $immuneAudit['context_export_allowed_now'] ?? false,
            'immune_constellation_promotion_allowed_now' => $immuneAudit['constellation_promotion_allowed_now'] ?? false,
            'content_intelligence_schema_version' => $contentIntelligence['schema_version'] ?? null,
            'content_type' => $contentIntelligence['content_type'] ?? null,
            'destination_enum' => data_get($contentIntelligence, 'destination.enum'),
            'quality_score' => data_get($contentIntelligence, 'quality.score'),
            'quality_label' => data_get($contentIntelligence, 'quality.label'),
            'blackink_defaulted' => data_get($contentIntelligence, 'blackink_defaulted', false),
            'content_intelligence_provider_export_allowed' => data_get($contentIntelligence, 'privacy.provider_export_allowed', false),
            'content_intelligence_open_brain_context_allowed' => data_get($contentIntelligence, 'privacy.open_brain_context_allowed', false),
            'content_file_integrity' => $this->fileIntegrity($fileExists),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reviewWorkflow(): ?array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $triage = is_array($metadata['triage'] ?? null) ? $metadata['triage'] : [];
        $semanticCuration = is_array($metadata['semantic_curation'] ?? null) ? $metadata['semantic_curation'] : [];
        $cognitiveQuarantine = is_array($metadata['cognitive_quarantine'] ?? null) ? $metadata['cognitive_quarantine'] : [];
        $proposalId = is_scalar($triage['proposal_id'] ?? null) ? (string) $triage['proposal_id'] : null;
        $proposalId ??= is_scalar($semanticCuration['proposal_id'] ?? null) ? (string) $semanticCuration['proposal_id'] : null;
        $memoryDeltaId = is_scalar($triage['memory_delta_id'] ?? null) ? (string) $triage['memory_delta_id'] : null;
        $memoryPromotion = is_array($metadata['memory_promotion'] ?? null) ? $metadata['memory_promotion'] : null;
        $verbatimPromotion = is_array($metadata['verbatim_promotion'] ?? null) ? $metadata['verbatim_promotion'] : null;

        if (! $proposalId && ! $memoryDeltaId && ! $memoryPromotion && ! $verbatimPromotion) {
            return null;
        }

        $actions = [];
        if ($proposalId && ! $memoryPromotion && ! $verbatimPromotion) {
            $actions[] = [
                'id' => 'ratify_semantic_note',
                'method' => 'POST',
                'path' => "/semantic/curation-proposals/{$proposalId}/accept",
                'requires_operator' => true,
            ];
            $actions[] = [
                'id' => 'promote_to_memory_registry',
                'method' => 'POST',
                'path' => "/semantic/curation-proposals/{$proposalId}/accept",
                'requires_operator' => true,
                'body' => ['promote_to_memory' => true],
            ];
            $actions[] = [
                'id' => 'promote_to_verbatim_store',
                'method' => 'POST',
                'path' => "/semantic/curation-proposals/{$proposalId}/accept",
                'requires_operator' => true,
                'body' => ['promote_to_verbatim' => true],
            ];
            $actions[] = [
                'id' => 'dismiss_proposal',
                'method' => 'POST',
                'path' => "/semantic/curation-proposals/{$proposalId}/dismiss",
                'requires_operator' => true,
            ];
            $actions[] = [
                'id' => 'postpone_proposal',
                'method' => 'POST',
                'path' => "/semantic/curation-proposals/{$proposalId}/postpone",
                'requires_operator' => true,
            ];
        }

        if ($memoryDeltaId && ! $memoryPromotion) {
            $actions[] = [
                'id' => 'accept_memory_delta',
                'method' => 'POST',
                'path' => "/ai/memory/deltas/{$memoryDeltaId}/review",
                'requires_operator' => true,
                'body' => ['action' => 'accept'],
            ];
            $actions[] = [
                'id' => 'reject_memory_delta',
                'method' => 'POST',
                'path' => "/ai/memory/deltas/{$memoryDeltaId}/review",
                'requires_operator' => true,
                'body' => ['action' => 'reject'],
            ];
        }

        return [
            'schema_version' => 'atlas.capture.review_workflow.v1',
            'status' => $this->reviewWorkflowStatus($triage, $semanticCuration, $memoryPromotion, $verbatimPromotion),
            'human_gate' => $triage['human_gate'] ?? $semanticCuration['human_gate'] ?? 'operator_review',
            'proposal_id' => $proposalId,
            'proposal_status' => $triage['proposal_status'] ?? $semanticCuration['proposal_status'] ?? null,
            'memory_delta_id' => $memoryDeltaId,
            'memory_delta_status' => $triage['memory_delta_status'] ?? null,
            'safety' => $this->reviewWorkflowSafety($cognitiveQuarantine),
            'memory_promotion' => $memoryPromotion ? [
                'status' => $memoryPromotion['status'] ?? 'promoted',
                'memory_entry_id' => $memoryPromotion['memory_entry_id'] ?? null,
                'receipt_schema_version' => $memoryPromotion['schema_version'] ?? null,
            ] : null,
            'verbatim_promotion' => $verbatimPromotion ? [
                'status' => $verbatimPromotion['status'] ?? 'promoted',
                'verbatim_memory_id' => $verbatimPromotion['verbatim_memory_id'] ?? null,
                'receipt_schema_version' => $verbatimPromotion['schema_version'] ?? null,
            ] : null,
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string,mixed>  $cognitiveQuarantine
     * @return array<string,mixed>
     */
    private function reviewWorkflowSafety(array $cognitiveQuarantine): array
    {
        $immuneAudit = is_array($cognitiveQuarantine['immune_audit'] ?? null) ? $cognitiveQuarantine['immune_audit'] : [];

        return [
            'schema_version' => 'atlas.capture.review_workflow_safety.v1',
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

    /**
     * @param  array<string,mixed>  $triage
     * @param  array<string,mixed>  $semanticCuration
     * @param  array<string,mixed>|null  $memoryPromotion
     * @param  array<string,mixed>|null  $verbatimPromotion
     */
    private function reviewWorkflowStatus(array $triage, array $semanticCuration, ?array $memoryPromotion, ?array $verbatimPromotion): string
    {
        if ($memoryPromotion || $verbatimPromotion) {
            return 'promoted';
        }

        return (string) ($triage['knowledge_state'] ?? $semanticCuration['status'] ?? $triage['status'] ?? 'pending_review');
    }
}
