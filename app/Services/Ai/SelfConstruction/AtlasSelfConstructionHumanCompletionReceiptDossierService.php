<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionHumanCompletionReceiptDossierService
{
    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function ksortRecursive(array $value): array
    {
        $this->ksortRecursiveByReference($value);

        return $value;
    }
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_dossier.v1';

    public const MODE = 'read_only_human_completion_receipt_dossier';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $completionAudit = (array) ($options['completion_audit'] ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit());
        $releaseDossierCriterion = $this->criterion($completionAudit, 'release_dossier_green');
        $replayDiffCriterion = $this->criterion($completionAudit, 'replay_diff_against_completion_snapshot_green');
        $runtimeCriterion = $this->criterion($completionAudit, 'runtime_gap_matrix_all_runtime_y');
        $smokeCriterion = $this->criterion($completionAudit, 'end_to_end_real_provider_smoke_green');
        $statusBatchCriterion = $this->criterion($completionAudit, 'certification_status_batch_green');
        $receiptInput = (array) ($options['completion_receipt'] ?? []);
        $receiptVerification = (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($receiptInput, [
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($releaseDossierCriterion, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replayDiffCriterion, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtimeCriterion, 'evidence.runtime_gap_matrix_hash', ''),
            'runtime_promotion_receipt_hash' => (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_receipt_hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.runtime_promotion_receipt_hash', '')),
            'real_provider_smoke_hash' => (string) data_get($smokeCriterion, 'evidence.smoke_hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.real_provider_smoke_hash', '')),
            'certification_status_batch_hash' => (string) data_get($statusBatchCriterion, 'evidence.hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
        ]);

        $receiptPreimage = [
            'receipt_id' => 'operator-os-complete-'.CarbonImmutable::now()->format('YmdHis'),
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current completion audit, runtime matrix, release dossier, replay diff, and real provider smoke evidence.',
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($releaseDossierCriterion, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replayDiffCriterion, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtimeCriterion, 'evidence.runtime_gap_matrix_hash', ''),
            'runtime_promotion_receipt_hash' => (string) data_get($runtimeCriterion, 'evidence.runtime_promotion_receipt_hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.runtime_promotion_receipt_hash', '')),
            'real_provider_smoke_hash' => (string) data_get($smokeCriterion, 'evidence.smoke_hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.real_provider_smoke_hash', '')),
            'certification_status_batch_hash' => (string) data_get($statusBatchCriterion, 'evidence.hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];

        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);
        $status = (string) data_get($receiptVerification, 'status') === 'passed' ? 'receipt_present' : 'operator_receipt_required';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_audit_snapshot' => [
                'status' => (string) data_get($completionAudit, 'status', 'unknown'),
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
                'failed_count' => (int) data_get($completionAudit, 'failed_count', count($failedCriteria)),
                'failed_criteria' => $failedCriteria,
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'completion_claim_allowed' => false,
            ],
            'release_dossier_snapshot' => [
                'release_dossier_hash' => (string) data_get($releaseDossierCriterion, 'evidence.hash', ''),
                'release_dossier_green' => (bool) data_get($releaseDossierCriterion, 'passed', false),
                'replay_diff_hash' => (string) data_get($replayDiffCriterion, 'evidence.diff_hash', ''),
                'stale_snapshot_detected' => (bool) data_get($releaseDossierCriterion, 'evidence.baseline_snapshot_capture_required', false),
                'action_required' => (bool) data_get($releaseDossierCriterion, 'evidence.baseline_snapshot_capture_required', false)
                    ? 'capture_fresh_replay_snapshot'
                    : 'none',
            ],
            'certification_status_batch' => [
                'status' => (bool) data_get($statusBatchCriterion, 'passed', false) ? 'passed' : 'blocked',
                'checked_count' => (int) data_get($statusBatchCriterion, 'evidence.checked_count', 0),
                'failed_count' => (int) data_get($statusBatchCriterion, 'evidence.failed_count', 0),
                'batch_hash' => (string) data_get($receiptPreimage, 'certification_status_batch_hash', ''),
            ],
            'human_receipt_preimage' => $receiptPreimage,
            'canonical_hash_preview' => [
                'canonical_hash_service' => AtlasSelfConstructionCompletionEvidenceHashService::class,
                'method' => 'humanCompletionReceiptHash',
                'placeholder_payload_hash_generated' => false,
                'operator_must_replace_placeholders' => true,
                'hash_changes_when_signed_by_or_reason_changes' => true,
            ],
            'blocker_bridge' => [
                'runtime_gap_matrix_all_runtime_y' => 'persist_runtime_promotion_receipt_first',
                'human_signed_os_complete_receipt_present' => 'sign_and_persist_this_receipt_after_other_evidence',
                'end_to_end_real_provider_smoke_green' => 'persist_real_provider_smoke_certification_first',
            ],
            'operator_signing_checklist' => [
                'rerun_completion_audit',
                'refresh_terminal_loop_operational_proof',
                'rerun_completion_audit_with_terminal_loop_operational_proof',
                'capture_replay_snapshot_if_release_dossier_is_stale',
                'persist_runtime_promotion_receipt',
                'persist_real_provider_smoke',
                'replace_receipt_placeholders',
                'compute_canonical_human_receipt_hash',
                'sign_human_completion_receipt',
                'persist_human_completion_receipt',
                'rerun_completion_audit_until_all_criteria_green',
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'completion_audit_with_terminal_loop_operational_proof_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            'safety_invariants' => [
                'no_autopromotion' => true,
                'no_silent_completion_claim' => true,
                'no_provider_call_by_dossier' => true,
                'no_token_spend_by_dossier' => true,
                'no_process_start_by_dossier' => true,
                'no_dispatch_by_dossier' => true,
            ],
            'receipt_verification' => [
                'status' => (string) data_get($receiptVerification, 'status', ''),
                'receipt_hash_matches_payload' => (bool) data_get($receiptVerification, 'receipt_hash_matches_payload', false),
                'violation_count' => (int) data_get($receiptVerification, 'violation_count', 0),
            ],
            'completion_claim_allowed' => false,
            'non_execution_guarantees' => [
                'human_completion_receipt_dossier_does_not_sign_for_operator',
                'human_completion_receipt_dossier_does_not_persist_receipts',
                'human_completion_receipt_dossier_does_not_call_provider',
                'human_completion_receipt_dossier_does_not_spend_tokens',
                'human_completion_receipt_dossier_does_not_dispatch_work',
                'human_completion_receipt_dossier_does_not_promote_completion',
            ],
        ];
        $payload['dossier_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterion(array $completionAudit, string $id): array
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $id) {
                return (array) $criterion;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['dossier_hash']);
        unset($payload['human_receipt_preimage']['receipt_id']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
