<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionCompletionOperatorActionPacketService
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
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_operator_action_packet.v1';

    public const MODE = 'read_only_completion_operator_action_packet';

    private const RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json';

    private const REAL_PROVIDER_SMOKE_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json';

    private const COMPLETION_RECEIPT_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json';

    private const TERMINAL_LOOP_PROOF_BINDING_PRIVATE_PATH = 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

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
        $gapRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $promotionRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (bool) ($row['runtime_y_candidate'] ?? false) === true
                && (bool) ($row['runtime_y'] ?? false) === false,
        ));
        $graduationHashes = [];
        foreach ($gapRows as $row) {
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
            'promoted_gap_ids' => array_values(array_map(static fn (array $row): string => (string) ($row['gap_id'] ?? ''), $gapRows)),
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
        $blockers = [];
        if ((string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.status') !== 'passed') {
            $missing[] = 'runtime_promotion_receipt';
            $blockers[] = [
                'id' => 'runtime_promotion_receipt',
                'blocker_type' => 'human',
                'requirement' => 'Operator must persist a signed runtime promotion receipt that matches the current graduation hashes.',
                'why_not_automatic' => 'Atlas cannot decide that the runtime gap matrix has graduated; an operator must explicitly approve the graduation hashes.',
                'expected_receipt_schema' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'expected_receipt_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'persist_command_with_canonical_submission_path' => $this->persistRuntimePromotionReceiptCommand(),
                'canonical_submission_private_storage_path' => self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH,
            ];
        }
        if ((string) data_get($humanReceipt, 'status') !== 'passed') {
            $missing[] = 'human_signed_os_complete_receipt';
            $blockers[] = [
                'id' => 'human_signed_os_complete_receipt',
                'blocker_type' => 'human',
                'requirement' => 'Operator must persist a human-signed OS-complete receipt referencing the final completion audit, runtime promotion and real provider smoke hashes.',
                'why_not_automatic' => 'Atlas refuses to self-promote completion. The OS-complete signature is the operator promise that the OS is truly done.',
                'expected_receipt_schema' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'expected_receipt_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'expected_receipt_command_with_canonical_submission_paths' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_command_with_canonical_submission_path' => $this->persistHumanCompletionReceiptCommand(),
                'canonical_submission_private_storage_path' => self::COMPLETION_RECEIPT_PRIVATE_PATH,
            ];
        }
        if ((string) data_get($realProviderSmoke, 'status') !== 'passed') {
            $missing[] = 'real_provider_claim_to_completion_smoke';
            $blockers[] = [
                'id' => 'real_provider_claim_to_completion_smoke',
                'blocker_type' => 'real_provider',
                'requirement' => 'Operator must run a real provider claim-to-completion smoke with collected work product, cost event, continuation summary and evidence ledger hash.',
                'why_not_automatic' => 'Atlas never starts a provider process and never spends tokens. The smoke requires a real, operator-observed provider call outside Atlas.',
                'expected_receipt_schema' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'expected_receipt_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'persist_command_with_canonical_submission_path' => $this->persistRealProviderSmokeCommand(),
                'canonical_submission_private_storage_path' => self::REAL_PROVIDER_SMOKE_PRIVATE_PATH,
            ];
        }
        $commands = [
            'verify_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'preflight_completion_evidence_submission' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
            'final_operator_evidence_closure_corridor' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
            'operator_evidence_artifact_template_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --json',
            'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'draft_runtime_promotion_receipt_to_canonical_submission_file' => $this->draftRuntimePromotionReceiptToCanonicalSubmissionFileCommand(),
            'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'draft_human_completion_receipt_with_canonical_submission_paths' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'prepare_real_provider_smoke_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
            'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'compose_completion_evidence_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'compose_completion_evidence_hashes_with_canonical_submission_paths' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --completion-receipt-json=@'.self::COMPLETION_RECEIPT_PRIVATE_PATH.' --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --json',
            'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'persist_runtime_promotion_receipt_with_canonical_submission_path' => $this->persistRuntimePromotionReceiptCommand(),
            'persist_real_provider_smoke_with_canonical_submission_path' => $this->persistRealProviderSmokeCommand(),
            'persist_human_completion_receipt_with_canonical_submission_path' => $this->persistHumanCompletionReceiptCommand(),
            'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
            'persist_terminal_loop_operational_proof_binding' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'run_completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'run_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'run_completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'run_completion_audit_diagnostic' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];
        $closureArtifactSequence = $this->closureArtifactSequence(
            runtimePromotionReady: (string) data_get($runtimeGapMatrix, 'runtime_promotion_receipt.status') === 'passed',
            realProviderSmokeReady: (string) data_get($realProviderSmoke, 'status') === 'passed',
            humanCompletionReady: (string) data_get($humanReceipt, 'status') === 'passed',
            commands: $commands,
        );
        $promptToArtifactChecklist = $this->promptToArtifactChecklist($closureArtifactSequence);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $missing === [] ? 'ready_for_operator_final_review' : 'operator_action_required',
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'missing_operator_artifacts' => $missing,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'blocker_classification' => [
                'human_blockers' => array_values(array_filter($blockers, static fn (array $b): bool => ($b['blocker_type'] ?? '') === 'human')),
                'real_provider_blockers' => array_values(array_filter($blockers, static fn (array $b): bool => ($b['blocker_type'] ?? '') === 'real_provider')),
                'technical_blockers' => array_values(array_filter($blockers, static fn (array $b): bool => ($b['blocker_type'] ?? '') === 'technical')),
            ],
            'expected_receipt_schemas' => [
                'runtime_promotion_receipt' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'human_signed_os_complete_receipt' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'real_provider_smoke' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'real_provider_smoke_runbook' => AtlasSelfConstructionRealProviderSmokeRunbookService::SCHEMA_VERSION,
                'completion_audit' => AtlasSelfConstructionOsCompletionAuditService::SCHEMA_VERSION,
                'release_dossier' => AgentControlPlaneReleaseDossierService::SCHEMA_VERSION,
                'terminal_loop_operational_proof_audit_binding' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            ],
            'human_judgment_required_reasons' => [
                'atlas_never_self_promotes_os_complete',
                'real_provider_call_is_outside_atlas_token_budget_and_kill_switch_belongs_to_operator',
                'runtime_graduation_must_be_signed_explicitly_with_current_hashes',
                'completion_audit_treats_unsigned_state_as_incomplete_by_contract',
                'release_dossier_green_alone_does_not_authorise_completion',
            ],
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => $this->stableTemplateHash($closureArtifactSequence),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => count(array_filter($promptToArtifactChecklist, static fn (array $row): bool => (bool) $row['passed'])),
            'prompt_to_artifact_checklist_hash' => $this->stableTemplateHash($promptToArtifactChecklist),
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
            'commands' => $commands,
            'canonical_submission_private_storage_paths' => [
                'runtime_promotion_receipt' => self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH,
                'real_provider_smoke' => self::REAL_PROVIDER_SMOKE_PRIVATE_PATH,
                'human_completion_receipt' => self::COMPLETION_RECEIPT_PRIVATE_PATH,
                'terminal_loop_operational_proof_binding' => self::TERMINAL_LOOP_PROOF_BINDING_PRIVATE_PATH,
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
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

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function terminalLoopOperationalProofBindingPersistCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.self::TERMINAL_LOOP_PROOF_BINDING_PRIVATE_PATH.' --json';
    }

    private function persistRuntimePromotionReceiptCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@'.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH.' --persist-runtime-promotion-receipt --json';
    }

    private function draftRuntimePromotionReceiptToCanonicalSubmissionFileCommand(): string
    {
        return 'mkdir -p storage/app/private/atlas/self-construction/operator-submissions && php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json | jq \'.agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload\' > '.self::RUNTIME_PROMOTION_RECEIPT_PRIVATE_PATH;
    }

    private function persistRealProviderSmokeCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@'.self::REAL_PROVIDER_SMOKE_PRIVATE_PATH.' --persist-completion-evidence --json';
    }

    private function persistHumanCompletionReceiptCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@'.self::COMPLETION_RECEIPT_PRIVATE_PATH.' --persist-completion-evidence --json';
    }

    /**
     * @param  array<string, string>  $commands
     * @return list<array<string, mixed>>
     */
    private function closureArtifactSequence(bool $runtimePromotionReady, bool $realProviderSmokeReady, bool $humanCompletionReady, array $commands): array
    {
        $operatorArtifactsReady = $runtimePromotionReady && $realProviderSmokeReady && $humanCompletionReady;

        return [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => $runtimePromotionReady ? 'passed' : 'blocked',
                'passed' => $runtimePromotionReady,
                'expected_receipt_schema' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'draft_command' => (string) ($commands['draft_runtime_promotion_receipt'] ?? ''),
                'persist_command' => (string) ($commands['persist_runtime_promotion_receipt'] ?? ''),
                'canonical_persist_command' => (string) ($commands['persist_runtime_promotion_receipt_with_canonical_submission_path'] ?? ''),
                'evidence_source' => 'runtime_gap_matrix',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 2,
                'artifact' => 'real_provider_smoke',
                'requirement' => 'end_to_end_real_provider_smoke_green',
                'blocker_type' => 'real_provider',
                'status' => $realProviderSmokeReady ? 'passed' : 'blocked',
                'passed' => $realProviderSmokeReady,
                'expected_receipt_schema' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'draft_command' => (string) ($commands['draft_real_provider_smoke'] ?? ''),
                'persist_command' => (string) ($commands['persist_real_provider_smoke'] ?? ''),
                'canonical_persist_command' => (string) ($commands['persist_real_provider_smoke_with_canonical_submission_path'] ?? ''),
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => $humanCompletionReady ? 'passed' : 'blocked',
                'passed' => $humanCompletionReady,
                'expected_receipt_schema' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'draft_command' => (string) ($commands['draft_human_completion_receipt'] ?? ''),
                'persist_command' => (string) ($commands['persist_human_completion_receipt'] ?? ''),
                'canonical_persist_command' => (string) ($commands['persist_human_completion_receipt_with_canonical_submission_path'] ?? ''),
                'evidence_source' => 'human_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => 'derived',
                'status' => $operatorArtifactsReady ? 'ready_for_completion_audit_rerun' : 'blocked_until_operator_evidence_green',
                'passed' => false,
                'expected_receipt_schema' => AtlasSelfConstructionOsCompletionAuditService::SCHEMA_VERSION,
                'draft_command' => (string) ($commands['run_completion_audit_with_canonical_terminal_loop_operational_proof'] ?? ''),
                'persist_command' => '',
                'canonical_persist_command' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @return list<array<string, mixed>>
     */
    private function promptToArtifactChecklist(array $closureArtifactSequence): array
    {
        return array_map(static fn (array $row): array => [
            'requirement' => (string) $row['requirement'],
            'artifact' => (string) $row['artifact'],
            'status' => (string) $row['status'],
            'passed' => (bool) $row['passed'],
            'blocker_type' => (string) $row['blocker_type'],
            'expected_receipt_schema' => (string) $row['expected_receipt_schema'],
            'evidence_source' => (string) $row['evidence_source'],
            'command' => (string) $row['draft_command'],
            'persist_command' => (string) $row['persist_command'],
            'canonical_persist_command' => (string) $row['canonical_persist_command'],
        ], $closureArtifactSequence);
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
}
