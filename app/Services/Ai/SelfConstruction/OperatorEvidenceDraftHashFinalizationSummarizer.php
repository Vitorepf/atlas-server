<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * DRAFT-HASH-FINALIZATION summary cluster, extracted from the god-class
 * {@see AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService}.
 *
 * Owns the three methods that turn the lower-level DraftHashFinalizerService output
 * (or the no-op case) into the readiness surface's draft_hash_finalization block,
 * and decides whether finalization is required for the readiness warning surface.
 */
final class OperatorEvidenceDraftHashFinalizationSummarizer
{
    /** @return array<string, mixed> */
    public function notRequested(): array
    {
        return [
            'schema_version' => AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::SCHEMA_VERSION,
            'mode' => AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService::MODE,
            'status' => 'not_requested',
            'workspace_loaded' => false,
            'artifact_count' => 0,
            'ready_artifact_count' => 0,
            'blocked_artifact_count' => 0,
            'written_artifact_count' => 0,
            'artifacts' => [],
            'finalizer_hash' => '',
        ];
    }

    /** @return array<string, mixed> */
    public function summary(array $finalization, string $operatorDraftWorkspacePath): array
    {
        $artifactSummaries = [];
        foreach ((array) data_get($finalization, 'artifacts', []) as $artifact => $details) {
            $artifactSummaries[(string) $artifact] = [
                'status' => (string) data_get($details, 'status', ''),
                'hash_field' => (string) data_get($details, 'hash_field', ''),
                'original_hash' => (string) data_get($details, 'original_hash', ''),
                'computed_hash' => (string) data_get($details, 'computed_hash', ''),
                'input_hash_matches_computed_hash' => (bool) data_get($details, 'input_hash_matches_computed_hash', false),
                'can_write_hash_to_draft' => (bool) data_get($details, 'can_write_hash_to_draft', false),
                'write_blocker' => (string) data_get($details, 'write_blocker', ''),
                'placeholder_fields' => (array) data_get($details, 'placeholder_fields', []),
                'invalid_hash_fields' => (array) data_get($details, 'invalid_hash_fields', []),
                'forbidden_flags_true' => (array) data_get($details, 'forbidden_flags_true', []),
            ];
        }

        return [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_readiness_draft_hash_finalization.v1',
            'status' => (string) data_get($finalization, 'status', 'not_requested'),
            'workspace_loaded' => (bool) data_get($finalization, 'workspace_loaded', false),
            'artifact_count' => (int) data_get($finalization, 'artifact_count', 0),
            'ready_artifact_count' => (int) data_get($finalization, 'ready_artifact_count', 0),
            'blocked_artifact_count' => (int) data_get($finalization, 'blocked_artifact_count', 0),
            'written_artifact_count' => (int) data_get($finalization, 'written_artifact_count', 0),
            'artifacts' => $artifactSummaries,
            'finalizer_hash' => (string) data_get($finalization, 'finalizer_hash', ''),
            'write_command' => $operatorDraftWorkspacePath === ''
                ? ''
                : 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-draft-hash-finalizer-status --operator-draft-workspace-path='.$operatorDraftWorkspacePath.' --write-computed-operator-draft-hashes --json',
            'can_write_from_submission_readiness' => false,
            'can_persist_from_submission_readiness' => false,
        ];
    }

    /** @param  array<string, mixed>  $finalization */
    public function required(array $finalization): bool
    {
        foreach ((array) data_get($finalization, 'artifacts', []) as $artifact) {
            if ((bool) data_get($artifact, 'can_write_hash_to_draft', false)
                && ! (bool) data_get($artifact, 'input_hash_matches_computed_hash', false)) {
                return true;
            }
        }

        return false;
    }
}
