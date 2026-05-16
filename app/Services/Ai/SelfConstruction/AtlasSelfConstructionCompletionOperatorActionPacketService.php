<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionOperatorActionPacketService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_operator_action_packet.v1';

    public const MODE = 'read_only_completion_operator_action_packet';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $humanReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, mixed>
     */
    public function build(array $runtimeGapMatrix, array $humanReceipt, array $realProviderSmoke, array $evidence = []): array
    {
        $releaseDossier = (array) ($evidence['release_dossier'] ?? $this->safeStatus('agentControlPlaneReleaseDossierStatus'));
        $replayDiff = (array) ($evidence['replay_diff'] ?? $this->safeStatus('agentControlPlaneReplayDiffStatus'));
        $statusBatch = (array) ($evidence['certification_status_batch'] ?? $this->safeStatus('agentControlPlaneCertificationStatusBatchStatus'));
        $rows = (array) data_get($runtimeGapMatrix, 'rows', []);
        $promotionRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true
                && (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $graduationHashes = [];
        foreach ($promotionRows as $row) {
            $gapId = (string) ($row['gap_id'] ?? '');
            if ($gapId !== '') {
                $graduationHashes[$gapId] = (string) ($row['graduation_evidence_hash'] ?? '');
            }
        }
        $runtimePromotionMatrixHash = (string) data_get(
            $runtimeGapMatrix,
            'expected_runtime_gap_matrix_hash_for_promotion_receipt',
            data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
        );
        $runtimePromotionReceiptHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.receipt_hash', '');
        if (preg_match('/^[a-f0-9]{64}$/', $runtimePromotionReceiptHash) !== 1) {
            $runtimePromotionReceiptHash = '<64_hex_runtime_promotion_receipt_hash_after_persistence>';
        }
        $realProviderSmokeHash = (string) data_get($realProviderSmoke, 'smoke_hash', '');
        if (preg_match('/^[a-f0-9]{64}$/', $realProviderSmokeHash) !== 1) {
            $realProviderSmokeHash = '<64_hex_real_provider_smoke_hash_after_persistence>';
        }

        $runtimePromotionTemplate = [
            'receipt_id' => 'operator-runtime-promotion-'.CarbonImmutable::now()->format('YmdHis'),
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current runtime graduation candidate hashes and approves runtime gap promotion without enabling execution directly.',
            'runtime_gap_matrix_hash' => $runtimePromotionMatrixHash,
            'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            'promoted_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $promotionRows)),
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ];
        $humanCompletionTemplate = [
            'receipt_id' => 'operator-os-complete-'.CarbonImmutable::now()->format('YmdHis'),
            'signed_by' => '<operator>',
            'reason' => 'Operator reviewed the current completion audit, runtime matrix, release dossier, replay diff, and real provider smoke evidence.',
            'completion_audit_hash' => '<64_hex_completion_audit_hash_after_runtime_promotion_and_real_smoke>',
            'release_dossier_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.release_dossier_hash', data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_hash', '')),
            'replay_diff_hash' => (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            'runtime_promotion_receipt_hash' => $runtimePromotionReceiptHash,
            'real_provider_smoke_hash' => $realProviderSmokeHash,
            'certification_status_batch_hash' => (string) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.batch_hash', data_get($statusBatch, 'agent_control_plane_certification_status_batch.batch_hash', '')),
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $realProviderSmokeTemplate = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => '<provider_run_id_from_operator_approved_real_provider_smoke>',
            'task_packet_id' => '<task_packet_id_exercised_claim_to_completion>',
            'observed_by' => '<operator_or_reviewer>',
            'approval_reason' => 'Operator approved a real provider claim-to-completion smoke and verified generated evidence.',
            'smoke_hash' => '<64_hex_smoke_hash_from_operator_approved_real_provider_smoke>',
            'operator_approval_receipt_hash' => '<64_hex_operator_approval_receipt_hash>',
            'evidence_ledger_hash' => '<64_hex_evidence_ledger_hash>',
            'work_product_manifest_hash' => '<64_hex_work_product_manifest_hash>',
            'cost_event_hash' => '<64_hex_cost_event_hash>',
            'continuation_summary_hash' => '<64_hex_continuation_summary_hash>',
            'provider_response_hash' => '<64_hex_provider_response_hash>',
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];

        $missing = [];
        if ((string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.status') !== 'passed') {
            $missing[] = 'runtime_promotion_receipt';
        }
        if ((string) data_get($humanReceipt, 'status') !== 'passed') {
            $missing[] = 'human_signed_os_complete_receipt';
        }
        if ((string) data_get($realProviderSmoke, 'status') !== 'passed') {
            $missing[] = 'real_provider_claim_to_completion_smoke';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $missing === [] ? 'ready_for_operator_final_review' : 'operator_action_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'missing_operator_artifacts' => $missing,
            'runtime_promotion_receipt_template' => $runtimePromotionTemplate,
            'human_completion_receipt_template' => $humanCompletionTemplate,
            'real_provider_smoke_template' => $realProviderSmokeTemplate,
            'template_hashes' => [
                'runtime_promotion_receipt_template_hash' => $this->stableTemplateHash($runtimePromotionTemplate),
                'human_completion_receipt_template_hash' => $this->stableTemplateHash($humanCompletionTemplate),
                'real_provider_smoke_template_hash' => $this->stableTemplateHash($realProviderSmokeTemplate),
            ],
            'template_validation_notes' => [
                'template_hashes_are_not_operator_receipt_hashes',
                'operator_must_replace_placeholders_before_persisting_evidence',
                'operator_receipt_hashes_must_match_canonical_payload_hashes',
                'runtime_promotion_receipt_must_match_current_graduation_hashes',
                'runtime_promotion_receipt_must_reference_current_closure_basis_hash',
                'human_completion_receipt_must_reference_post_smoke_completion_audit_hash',
                'human_completion_receipt_must_reference_runtime_promotion_receipt_hash_and_real_provider_smoke_hash',
                'real_provider_smoke_must_come_from_operator_approved_real_provider_run',
            ],
            'runtime_promotion_receipt_runbook' => (new AtlasSelfConstructionRuntimePromotionReceiptRunbookService)->build($runtimePromotionTemplate),
            'human_completion_receipt_runbook' => (new AtlasSelfConstructionHumanCompletionReceiptRunbookService)->build($humanCompletionTemplate),
            'real_provider_smoke_runbook' => (new AtlasSelfConstructionRealProviderSmokeRunbookService)->build($realProviderSmokeTemplate),
            'commands' => [
                'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'preflight_completion_evidence_submission' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                'final_operator_evidence_closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
                'operator_evidence_artifact_template_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'prepare_real_provider_smoke_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'compose_completion_evidence_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'run_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
            'non_execution_guarantees' => [
                'operator_action_packet_does_not_start_codex',
                'operator_action_packet_does_not_call_provider',
                'operator_action_packet_does_not_dispatch_work',
                'operator_action_packet_does_not_spend_tokens',
                'operator_action_packet_does_not_enable_self_programming',
                'operator_action_packet_does_not_sign_for_operator',
            ],
        ];
        $payload['operator_action_packet_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $template */
    private function stableTemplateHash(array $template): string
    {
        unset($template['receipt_id']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($template), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function safeStatus(string $method): array
    {
        try {
            return method_exists($this->readiness, $method) ? $this->readiness->{$method}() : ['status' => 'method_missing'];
        } catch (\Throwable $e) {
            return ['status' => 'exception', 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['operator_action_packet_hash']);
        unset($payload['runtime_promotion_receipt_template']['receipt_id']);
        unset($payload['human_completion_receipt_template']['receipt_id']);

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
