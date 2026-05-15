<?php

namespace App\Services\Ai\SelfConstruction;

final class AtlasSelfConstructionCompletionAuditBlockerExplainerService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_audit_blocker_explainer.v1';

    public const MODE = 'read_only_completion_audit_blocker_explainer';

    /** @param array<string, mixed> $completionAudit */
    public function build(array $completionAudit): array
    {
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);
        $blockers = array_map(fn (string $blocker): array => $this->blocker($blocker, $completionAudit), $failedCriteria);
        $knownIds = array_column($blockers, 'blocker_id');

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'completion_claim_allowed' => false,
            'blockers' => $blockers,
            'known_blocker_ids' => $knownIds,
            'remaining_blocker_count' => count($blockers),
            'dependency_graph' => [
                'ordered_closure_path' => [
                    'capture_fresh_snapshot_if_release_dossier_stale',
                    'draft_runtime_promotion_receipt',
                    'persist_runtime_promotion_receipt',
                    'prepare_real_provider_smoke_offline_harness',
                    'draft_real_provider_smoke_certification',
                    'persist_real_provider_smoke_certification',
                    'compose_completion_evidence_hashes',
                    'draft_human_completion_receipt',
                    'persist_human_completion_receipt',
                    'rerun_completion_audit',
                    'promote_next_stage_only_after_all_criteria_green',
                ],
                'hard_dependencies' => [
                    'human_completion_receipt_depends_on_runtime_promotion_and_real_provider_smoke',
                    'completion_claim_depends_on_completion_audit_status_complete',
                    'runtime_promotion_depends_on_current_runtime_gap_matrix_hash',
                    'real_provider_smoke_depends_on_observed_provider_call_and_token_spend',
                ],
            ],
            'closure_plan' => [
                [
                    'phase' => 'refresh_baseline',
                    'acceptance_criteria' => ['release_dossier_green', 'replay_diff_has_current_snapshot'],
                    'stop_condition' => 'stop_if_release_dossier_status_is_not_available',
                ],
                [
                    'phase' => 'runtime_promotion',
                    'acceptance_criteria' => ['runtime_promotion_receipt_draft_ready', 'runtime_promotion_receipt_status_passed', 'runtime_gap_matrix_all_runtime_y'],
                    'stop_condition' => 'stop_if_receipt_hash_mismatch_or_promoted_gap_ids_drift',
                ],
                [
                    'phase' => 'real_provider_smoke',
                    'acceptance_criteria' => ['offline_harness_prepared', 'real_provider_smoke_draft_ready', 'real_provider_smoke_status_passed', 'cost_and_work_product_evidence_present'],
                    'stop_condition' => 'stop_if_provider_smoke_is_synthetic_or_missing_cost_event',
                ],
                [
                    'phase' => 'completion_evidence_hash_composition',
                    'acceptance_criteria' => ['runtime_receipt_hash_composed', 'real_provider_smoke_hash_composed', 'human_receipt_hash_composed'],
                    'stop_condition' => 'stop_if_any_payload_contains_placeholders_or_runtime_enabling_flags',
                ],
                [
                    'phase' => 'human_completion_receipt',
                    'acceptance_criteria' => ['human_completion_receipt_draft_ready', 'human_receipt_status_passed', 'receipt_references_post_smoke_completion_audit_hash'],
                    'stop_condition' => 'stop_if_operator_receipt_contains_placeholders',
                ],
                [
                    'phase' => 'final_audit',
                    'acceptance_criteria' => ['completion_audit_status_complete', 'failed_criteria_empty'],
                    'stop_condition' => 'stop_if_any_completion_criterion_is_failed',
                ],
            ],
            'anti_cheat_policy' => [
                'reject_fake_real_provider_smoke',
                'reject_receipt_hash_mismatch',
                'reject_runtime_autopromotion',
                'reject_completion_claim_without_human_receipt',
                'reject_stale_release_dossier',
                'reject_provider_smoke_without_cost_work_product_ledger_and_continuation_evidence',
            ],
            'command_plan' => [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'final_operator_evidence_closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'operator_evidence_artifact_template_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'prepare_real_provider_smoke_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'compose_completion_evidence_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'capture_snapshot_if_stale' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'machine_status' => [
                'status' => 'available',
                'can_close_automatically' => false,
                'human_required' => in_array('human_signed_os_complete_receipt_present', $knownIds, true),
                'real_provider_required' => in_array('end_to_end_real_provider_smoke_green', $knownIds, true),
                'remaining_blocker_count' => count($blockers),
                'closure_ready' => $blockers === [],
            ],
            'non_execution_guarantees' => [
                'blocker_explainer_does_not_persist_receipts',
                'blocker_explainer_does_not_call_provider',
                'blocker_explainer_does_not_spend_tokens',
                'blocker_explainer_does_not_dispatch_work',
                'blocker_explainer_does_not_enable_runtime',
                'blocker_explainer_does_not_promote_completion',
            ],
        ];
        $payload['explainer_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $completionAudit */
    private function blocker(string $blockerId, array $completionAudit): array
    {
        $evidence = $this->criterionEvidence($blockerId, $completionAudit);

        return match ($blockerId) {
            'runtime_gap_matrix_all_runtime_y' => [
                'blocker_id' => $blockerId,
                'severity' => 'critical',
                'owner' => 'operator',
                'current_state' => (string) data_get($evidence, 'status', 'blocked'),
                'current_evidence_context' => $this->currentEvidenceContext($blockerId, $completionAudit, $evidence),
                'required_evidence' => ['runtime_promotion_receipt', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'runtime_promotion_closure_basis_hash', 'graduation_evidence_hashes'],
                'existing_service_that_validates_it' => AtlasSelfConstructionRuntimePromotionReceiptService::class,
                'existing_runbook_if_any' => AtlasSelfConstructionRuntimePromotionReceiptRunbookService::class,
                'exact_closure_condition' => 'Runtime promotion receipt verifies against current runtime matrix and every gap row becomes runtime_y=true.',
                'exact_command_family_to_rerun' => 'atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status',
                'safety_constraints' => ['no_runtime_autopromotion', 'runtime_enabled_flags_stay_false_until_verified_receipt'],
                'why_it_cannot_be_auto_closed' => 'Runtime promotion changes completion eligibility and must be explicitly operator-approved.',
            ],
            'human_signed_os_complete_receipt_present' => [
                'blocker_id' => $blockerId,
                'severity' => 'critical',
                'owner' => 'operator',
                'current_state' => (string) data_get($evidence, 'status', 'blocked_missing_operator_receipt'),
                'current_evidence_context' => $this->currentEvidenceContext($blockerId, $completionAudit, $evidence),
                'required_evidence' => ['human_signed_os_complete_receipt', 'completion_audit_hash', 'release_dossier_hash', 'replay_diff_hash'],
                'existing_service_that_validates_it' => AtlasSelfConstructionHumanCompletionReceiptVerifierService::class,
                'existing_runbook_if_any' => AtlasSelfConstructionHumanCompletionReceiptRunbookService::class,
                'exact_closure_condition' => 'Human completion receipt verifies after runtime promotion and real provider smoke evidence are present.',
                'exact_command_family_to_rerun' => 'atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status',
                'safety_constraints' => ['no_silent_completion_claim', 'receipt_hash_must_match_payload', 'placeholders_forbidden'],
                'why_it_cannot_be_auto_closed' => 'The OS-complete claim requires an explicit human signature over current evidence.',
            ],
            'end_to_end_real_provider_smoke_green' => [
                'blocker_id' => $blockerId,
                'severity' => 'critical',
                'owner' => 'provider-smoke',
                'current_state' => (string) data_get($evidence, 'status', 'blocked_missing_real_provider_smoke'),
                'current_evidence_context' => $this->currentEvidenceContext($blockerId, $completionAudit, $evidence),
                'required_evidence' => ['provider_run_id', 'task_packet_id', 'cost_event_hash', 'work_product_manifest_hash', 'evidence_ledger_hash', 'continuation_summary_hash'],
                'existing_service_that_validates_it' => AtlasSelfConstructionRealProviderSmokeCertificationService::class,
                'existing_runbook_if_any' => AtlasSelfConstructionRealProviderSmokeRunbookService::class,
                'exact_closure_condition' => 'A single operator-approved real provider packet runs claim-to-completion with cost, work product, ledger and continuation evidence.',
                'exact_command_family_to_rerun' => 'atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status',
                'safety_constraints' => ['fake_smoke_forbidden', 'provider_call_must_be_observed', 'token_spend_must_be_observed'],
                'why_it_cannot_be_auto_closed' => 'The required proof is a real provider observation, not a local simulation or proxy.',
            ],
            'release_dossier_green' => [
                'blocker_id' => $blockerId,
                'severity' => 'high',
                'owner' => 'system',
                'current_state' => (string) data_get($evidence, 'status', 'warning'),
                'current_evidence_context' => $this->currentEvidenceContext($blockerId, $completionAudit, $evidence),
                'required_evidence' => ['current_replay_snapshot', 'release_dossier_hash'],
                'existing_service_that_validates_it' => AgentControlPlaneReleaseDossierService::class,
                'existing_runbook_if_any' => 'agent_control_plane_replay_snapshot_store_capture',
                'exact_closure_condition' => 'Release dossier status is available and baseline_snapshot_capture_required=false.',
                'exact_command_family_to_rerun' => 'atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture',
                'safety_constraints' => ['snapshot_capture_must_not_execute_runtime', 'release_dossier_must_not_hide_stale_baseline'],
                'why_it_cannot_be_auto_closed' => 'A stale baseline needs an explicit snapshot refresh so replay evidence stays auditable.',
            ],
            default => [
                'blocker_id' => $blockerId,
                'severity' => 'high',
                'owner' => 'unknown',
                'current_state' => (string) data_get($evidence, 'status', 'unknown'),
                'current_evidence_context' => $this->currentEvidenceContext($blockerId, $completionAudit, $evidence),
                'required_evidence' => [],
                'existing_service_that_validates_it' => '',
                'existing_runbook_if_any' => '',
                'exact_closure_condition' => 'Unknown blocker must be mapped to a concrete verifier before closure.',
                'exact_command_family_to_rerun' => 'atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status',
                'safety_constraints' => ['unknown_blocker_cannot_be_auto_closed'],
                'why_it_cannot_be_auto_closed' => 'Unknown completion blockers cannot be treated as green by proxy.',
            ],
        };
    }

    /** @param array<string, mixed> $completionAudit */
    private function currentEvidenceContext(string $blockerId, array $completionAudit, array $evidence): array
    {
        $operatorPacket = (array) data_get($completionAudit, 'operator_action_packet', []);

        return match ($blockerId) {
            'runtime_gap_matrix_all_runtime_y' => [
                'runtime_gap_matrix_hash' => (string) data_get($evidence, 'runtime_gap_matrix_hash', data_get($operatorPacket, 'runtime_promotion_receipt_template.runtime_gap_matrix_hash', '')),
                'runtime_promotion_basis_hash' => (string) data_get($operatorPacket, 'runtime_promotion_receipt_template.runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($operatorPacket, 'runtime_promotion_receipt_template.runtime_promotion_closure_basis_hash', ''),
                'runtime_gap_count' => (int) data_get($evidence, 'runtime_gap_count', 0),
                'blocked_gap_ids' => (array) data_get($evidence, 'blocked_gap_ids', []),
                'not_yet_runtime_capable' => (array) data_get($evidence, 'not_yet_runtime_capable', []),
                'promoted_gap_ids_template' => (array) data_get($operatorPacket, 'runtime_promotion_receipt_template.promoted_gap_ids', []),
                'graduation_evidence_hashes_template' => (array) data_get($operatorPacket, 'runtime_promotion_receipt_template.graduation_evidence_hashes', []),
                'template_hash' => (string) data_get($operatorPacket, 'template_hashes.runtime_promotion_receipt_template_hash', ''),
                'operator_artifact_missing' => in_array('runtime_promotion_receipt', (array) data_get($operatorPacket, 'missing_operator_artifacts', []), true),
            ],
            'human_signed_os_complete_receipt_present' => [
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'receipt_id' => (string) data_get($evidence, 'receipt_id', ''),
                'receipt_hash' => (string) data_get($evidence, 'receipt_hash', ''),
                'receipt_verification_hash' => (string) data_get($evidence, 'receipt_verification_hash', ''),
                'violation_count' => (int) data_get($evidence, 'violation_count', 0),
                'human_completion_receipt_template_hash' => (string) data_get($operatorPacket, 'template_hashes.human_completion_receipt_template_hash', ''),
                'operator_artifact_missing' => in_array('human_signed_os_complete_receipt', (array) data_get($operatorPacket, 'missing_operator_artifacts', []), true),
            ],
            'end_to_end_real_provider_smoke_green' => [
                'smoke_hash' => (string) data_get($evidence, 'smoke_hash', ''),
                'certification_hash' => (string) data_get($evidence, 'certification_hash', ''),
                'violation_count' => (int) data_get($evidence, 'violation_count', 0),
                'real_provider_smoke_template_hash' => (string) data_get($operatorPacket, 'template_hashes.real_provider_smoke_template_hash', ''),
                'required_observation_flags' => [
                    'provider_call_observed',
                    'token_spend_observed',
                    'claim_to_completion_observed',
                    'work_product_collected',
                    'operator_supplied_evidence',
                    'real_provider_run_observed_by_operator',
                ],
                'operator_artifact_missing' => in_array('real_provider_claim_to_completion_smoke', (array) data_get($operatorPacket, 'missing_operator_artifacts', []), true),
            ],
            'release_dossier_green' => [
                'status' => (string) data_get($evidence, 'status', ''),
                'release_dossier_hash' => (string) data_get($evidence, 'hash', ''),
                'baseline_capture_readiness_status' => (string) data_get($evidence, 'baseline_capture_readiness_status', ''),
                'baseline_snapshot_capture_required' => (bool) data_get($evidence, 'baseline_snapshot_capture_required', true),
            ],
            default => [
                'raw_evidence_status' => (string) data_get($evidence, 'status', 'unknown'),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            ],
        };
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterionEvidence(string $blockerId, array $completionAudit): array
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $blockerId) {
                return (array) ($criterion['evidence'] ?? []);
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['explainer_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
