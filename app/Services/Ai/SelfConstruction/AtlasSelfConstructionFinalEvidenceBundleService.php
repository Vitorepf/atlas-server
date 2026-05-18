<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use ReflectionClass;
use Throwable;

final class AtlasSelfConstructionFinalEvidenceBundleService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.final_evidence_bundle.v1';

    public const MODE = 'read_only_final_evidence_bundle';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function build(array $options = []): array
    {
        $runtimeGapMatrix = (array) ($options['runtime_gap_matrix'] ?? (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
            'persist_runtime_promotion_receipt' => false,
        ]));
        $humanReceipt = (array) ($options['human_receipt'] ?? (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify((array) ($options['completion_receipt'] ?? [])));
        $realProviderSmoke = (array) ($options['real_provider_smoke_result'] ?? (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify((array) ($options['real_provider_smoke'] ?? [])));
        $operatorActionPacket = (array) ($options['operator_action_packet'] ?? (new AtlasSelfConstructionCompletionOperatorActionPacketService($this->readiness))->build($runtimeGapMatrix, $humanReceipt, $realProviderSmoke));
        $hashComposer = (array) ($options['completion_evidence_hash_composer'] ?? (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
            'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
            'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
        ]));
        $completionAudit = (array) ($options['completion_audit'] ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit(array_merge($options, [
            'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
            'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
            'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
        ])));
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);

        $components = [
            'completion_evidence_lockfile' => $this->optionalComponent(
                AtlasSelfConstructionCompletionEvidenceLockfileService::class,
                ['build'],
                [[
                    'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                    'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                    'receipt_hash' => (string) data_get($humanReceipt, 'receipt_hash', ''),
                    'smoke_hash' => (string) data_get($realProviderSmoke, 'smoke_hash', ''),
                    'failed_criteria' => $failedCriteria,
                ]],
            ),
            'completion_anti_fraud_matrix' => $this->optionalComponent(
                AtlasSelfConstructionCompletionAntiFraudMatrixService::class,
                ['evaluate'],
                [$this->antiFraudEvidence(
                    $completionAudit,
                    $runtimeGapMatrix,
                    $humanReceipt,
                    $realProviderSmoke,
                    (array) ($options['completion_receipt'] ?? []),
                    (array) ($options['real_provider_smoke'] ?? []),
                )],
            ),
            'runtime_promotion_closure_pack' => $this->optionalComponent(
                AtlasSelfConstructionRuntimePromotionClosurePackService::class,
                ['build', 'status'],
                [['runtime_gap_matrix' => $runtimeGapMatrix]],
            ),
            'runtime_promotion_evidence_dossier' => $this->optionalComponent(
                AtlasSelfConstructionRuntimePromotionEvidenceDossierService::class,
                ['build', 'dossier', 'status'],
                [['runtime_gap_matrix' => $runtimeGapMatrix]],
            ),
            'human_completion_receipt_dossier' => $this->optionalComponent(
                AtlasSelfConstructionHumanCompletionReceiptDossierService::class,
                ['build', 'dossier', 'status'],
                [
                    [
                        'completion_audit' => $completionAudit,
                        'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                    ],
                ],
            ),
            'human_completion_receipt_preflight' => $this->optionalComponent(
                AtlasSelfConstructionHumanCompletionReceiptPreflightService::class,
                ['build', 'status'],
                [['completion_audit' => $completionAudit]],
            ),
            'real_provider_smoke_evidence_dossier' => $this->optionalComponent(
                AtlasSelfConstructionRealProviderSmokeEvidenceDossierService::class,
                ['build', 'dossier', 'status'],
                [['real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? [])]],
            ),
            'real_provider_smoke_offline_harness' => $this->optionalComponent(
                AtlasSelfConstructionRealProviderSmokeOfflineHarnessService::class,
                ['build', 'status'],
            ),
            'completion_audit_blocker_explainer' => $this->optionalComponent(
                AtlasSelfConstructionCompletionAuditBlockerExplainerService::class,
                ['build', 'explain', 'status'],
                [$completionAudit],
            ),
            'completion_operator_action_packet' => $this->componentFromPayload(
                AtlasSelfConstructionCompletionOperatorActionPacketService::class,
                'build',
                $operatorActionPacket,
            ),
            'completion_evidence_hash_composer' => $this->componentFromPayload(
                AtlasSelfConstructionCompletionEvidenceHashComposerService::class,
                'compose',
                $hashComposer,
            ),
            'completion_audit_status' => $this->componentFromPayload(
                AtlasSelfConstructionOsCompletionAuditService::class,
                'audit',
                $completionAudit,
            ),
            'runtime_gap_matrix' => $this->componentFromPayload(
                AtlasSelfConstructionRuntimeGapMatrixService::class,
                'matrix',
                $runtimeGapMatrix,
            ),
        ];

        $missingComponents = array_values(array_keys(array_filter(
            $components,
            static fn (array $component): bool => (bool) ($component['available'] ?? false) === false,
        )));
        $completionAuditGreen = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false) === true;
        $runtimePromotionReady = (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false) === true;
        $realProviderSmokeReady = (string) data_get($realProviderSmoke, 'status') === 'passed';
        $humanCompletionReceiptReady = (string) data_get($humanReceipt, 'status') === 'passed';
        $releaseDossierReady = $this->releaseDossierReady($completionAudit);
        $terminalLoopOperationalProofReady = (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.status') === 'passed'
            && (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.passed', false) === true;
        $finalCompletionAllowed = $runtimePromotionReady
            && $realProviderSmokeReady
            && $humanCompletionReceiptReady
            && $releaseDossierReady
            && $terminalLoopOperationalProofReady
            && $completionAuditGreen
            && $failedCriteria === [];
        $closureArtifactSequence = $this->closureArtifactSequence(
            runtimePromotionReady: $runtimePromotionReady,
            realProviderSmokeReady: $realProviderSmokeReady,
            humanCompletionReceiptReady: $humanCompletionReceiptReady,
            completionAuditGreen: $completionAuditGreen,
        );
        $promptToArtifactChecklist = array_map(
            static fn (array $row): array => [
                'requirement' => $row['requirement'],
                'artifact' => $row['artifact'],
                'status' => $row['status'],
                'passed' => $row['passed'],
                'blocker_type' => $row['blocker_type'],
                'expected_receipt_schema' => $row['expected_receipt_schema'],
                'evidence_source' => $row['evidence_source'],
                'command' => $row['draft_command'],
                'persist_command' => $row['persist_command'],
            ],
            $closureArtifactSequence,
        );
        $commandsToRerun = [
            'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'completion_evidence_hash_composer' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'completion_audit_diagnostic' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_binding_export' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json',
            'completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'capture_snapshot_if_stale' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
        ];
        $finalOperatorNextActionShellPacket = $this->finalOperatorNextActionShellPacket($closureArtifactSequence, $commandsToRerun);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $missingComponents === [] ? 'available' : 'degraded',
            'bundle_identity' => [
                'schema_version' => self::SCHEMA_VERSION,
                'bundle_id' => '',
                'bundle_hash' => '',
            ],
            'component_registry' => $components,
            'final_readiness_map' => [
                'runtime_promotion_ready' => $runtimePromotionReady,
                'real_provider_smoke_ready' => $realProviderSmokeReady,
                'human_completion_receipt_ready' => $humanCompletionReceiptReady,
                'release_dossier_ready' => $releaseDossierReady,
                'terminal_loop_operational_proof_ready' => $terminalLoopOperationalProofReady,
                'completion_audit_green' => $completionAuditGreen,
                'final_completion_allowed' => $finalCompletionAllowed,
            ],
            'evidence_dependencies' => [
                'ordered_closure_path' => [
                    'refresh_replay_snapshot_when_stale',
                    'verify_runtime_promotion_closure_pack',
                    'persist_runtime_promotion_receipt',
                    'persist_real_provider_smoke_certification',
                    'persist_human_os_completion_receipt',
                    'rerun_completion_audit',
                    'refresh_terminal_loop_operational_proof',
                    'capture_replay_snapshot_after_terminal_loop_operational_proof',
                    'rerun_completion_audit_with_terminal_loop_operational_proof',
                    'promote_next_stage_only_after_audit_complete',
                ],
                'missing_components' => $missingComponents,
                'missing_real_evidence' => $this->missingRealEvidence($runtimePromotionReady, $realProviderSmokeReady, $humanCompletionReceiptReady, $releaseDossierReady),
                'failed_criteria' => $failedCriteria,
                'human_required' => ! $humanCompletionReceiptReady,
                'real_provider_required' => ! $realProviderSmokeReady,
            ],
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => $this->stableHash($closureArtifactSequence),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => count(array_filter($promptToArtifactChecklist, static fn (array $row): bool => (bool) $row['passed'])),
            'prompt_to_artifact_checklist_hash' => $this->stableHash($promptToArtifactChecklist),
            'safety_invariants' => [
                'no_execution' => true,
                'no_provider_call' => true,
                'no_token_spend' => true,
                'no_dispatch' => true,
                'no_adapter_execution' => true,
                'no_self_programming' => true,
                'no_completion_claim' => ! $finalCompletionAllowed,
            ],
            'final_operator_packet' => [
                'what_is_ready' => $this->readyItems($runtimePromotionReady, $realProviderSmokeReady, $humanCompletionReceiptReady, $releaseDossierReady, $completionAuditGreen),
                'what_is_blocked' => $failedCriteria,
                'what_must_be_signed' => array_values(array_filter([
                    $runtimePromotionReady ? null : 'runtime_promotion_receipt',
                    $humanCompletionReceiptReady ? null : 'human_signed_os_complete_receipt',
                ])),
                'what_must_be_run_with_real_provider' => $realProviderSmokeReady ? [] : ['real_provider_claim_to_completion_smoke'],
                'commands_to_rerun' => $commandsToRerun,
                'next_action_shell_packet' => $finalOperatorNextActionShellPacket,
                'final_verification_sequence' => [
                    [
                        'id' => 'refresh_terminal_loop_operational_proof_and_export_binding',
                        'command_key' => 'terminal_loop_operational_proof_binding_export',
                        'must_run_after_real_evidence_persisted' => true,
                        'may_make_release_snapshot_stale' => true,
                    ],
                    [
                        'id' => 'capture_replay_snapshot_after_terminal_loop_operational_proof',
                        'command_key' => 'capture_snapshot_if_stale',
                        'must_run_after' => 'refresh_terminal_loop_operational_proof_and_export_binding',
                        'reason' => 'terminal_loop_operational_proof_records_local_dry_run_evidence_that_can_change_the_deterministic_replay_hash',
                    ],
                    [
                        'id' => 'rerun_completion_audit_with_terminal_loop_operational_proof_binding',
                        'command_key' => 'completion_audit_with_canonical_terminal_loop_operational_proof',
                        'must_run_after' => 'capture_replay_snapshot_after_terminal_loop_operational_proof',
                        'success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
                    ],
                ],
                'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof' => true,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
                'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
                'stop_conditions' => [
                    'do_not_accept_placeholder_receipts',
                    'do_not_accept_hash_mismatch',
                    'do_not_accept_fake_provider_smoke',
                    'do_not_promote_completion_while_failed_criteria_exist',
                    'do_not_enable_runtime_without_verified_runtime_promotion_receipt',
                ],
            ],
            'machine_status' => [
                'status' => $missingComponents === [] ? 'available' : 'degraded',
                'missing_component_count' => count($missingComponents),
                'blocker_count' => count($failedCriteria),
                'completion_claim_allowed' => $finalCompletionAllowed,
                'next_action' => $finalCompletionAllowed
                    ? 'operator_may_review_final_bundle_and_promote_next_stage'
                    : 'collect_missing_real_evidence_and_rerun_completion_audit',
            ],
            'non_execution_guarantees' => [
                'final_evidence_bundle_does_not_persist_receipts',
                'final_evidence_bundle_does_not_call_provider',
                'final_evidence_bundle_does_not_spend_tokens',
                'final_evidence_bundle_does_not_dispatch_work',
                'final_evidence_bundle_does_not_execute_adapter',
                'final_evidence_bundle_does_not_start_processes',
                'final_evidence_bundle_does_not_promote_completion',
            ],
        ];

        $bundleHash = $this->stableHash($payload);
        $payload['bundle_identity']['bundle_hash'] = $bundleHash;
        $payload['bundle_identity']['bundle_id'] = 'final-evidence-bundle-'.substr($bundleHash, 0, 16);
        $payload['machine_status']['final_bundle_hash'] = $bundleHash;

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function closureArtifactSequence(
        bool $runtimePromotionReady,
        bool $realProviderSmokeReady,
        bool $humanCompletionReceiptReady,
        bool $completionAuditGreen,
    ): array {
        return [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => $runtimePromotionReady ? 'passed' : 'blocked',
                'passed' => $runtimePromotionReady,
                'expected_receipt_schema' => 'atlas.self_construction.runtime_promotion_receipt.v1',
                'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'canonical_submission_private_storage_path' => 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
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
                'expected_receipt_schema' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
                'canonical_submission_private_storage_path' => 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => $humanCompletionReceiptReady ? 'passed' : 'blocked',
                'passed' => $humanCompletionReceiptReady,
                'expected_receipt_schema' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'draft_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
                'canonical_submission_private_storage_path' => 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
                'evidence_source' => 'human_signed_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => $completionAuditGreen ? 'none' : 'derived',
                'status' => $completionAuditGreen ? 'passed' : 'blocked_until_operator_evidence_green',
                'passed' => $completionAuditGreen,
                'expected_receipt_schema' => 'atlas.self_construction.os_completion_audit.v1',
                'draft_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'persist_command' => '',
                'canonical_submission_private_storage_path' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    /**
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @param  array<string, string>  $commandsToRerun
     * @return array<string, mixed>
     */
    private function finalOperatorNextActionShellPacket(array $closureArtifactSequence, array $commandsToRerun): array
    {
        $nextStep = collect($closureArtifactSequence)->first(static fn (array $step): bool => (bool) ($step['passed'] ?? false) === false);
        if (! is_array($nextStep)) {
            $nextStep = [
                'artifact' => 'none',
                'requirement' => 'none',
                'status' => 'passed',
                'draft_command' => '',
                'persist_command' => '',
                'expected_receipt_schema' => '',
                'blocker_type' => 'none',
            ];
        }

        $exactCommand = (string) ($nextStep['draft_command'] ?? '');
        $persistCommand = (string) ($nextStep['persist_command'] ?? '');
        $placeholders = $this->commandPlaceholders($exactCommand.' '.$persistCommand);
        $packet = [
            'schema_version' => 'atlas.self_construction.final_evidence_bundle_next_action_shell_packet.v1',
            'mode' => 'read_only_next_action_shell_packet',
            'status' => $placeholders === [] ? 'copy_ready_after_fresh_status_review' : 'blocked_placeholder_replacement_required',
            'current_required_operator_artifact' => (string) ($nextStep['artifact'] ?? ''),
            'current_requirement' => (string) ($nextStep['requirement'] ?? ''),
            'current_blocker_type' => (string) ($nextStep['blocker_type'] ?? ''),
            'expected_receipt_schema' => (string) ($nextStep['expected_receipt_schema'] ?? ''),
            'exact_command' => $exactCommand,
            'persist_command' => $persistCommand,
            'exact_command_hash' => $exactCommand === '' ? '' : hash('sha256', $exactCommand),
            'persist_command_hash' => $persistCommand === '' ? '' : hash('sha256', $persistCommand),
            'placeholder_count' => count($placeholders),
            'placeholders' => $placeholders,
            'copy_safe' => $placeholders === [] && $exactCommand !== '',
            'requires_fresh_status_before_copy' => true,
            'requires_fresh_preflight_before_persist' => true,
            'do_not_run_persist_command_until_verifier_green' => true,
            'can_resume_without_chat_history' => true,
            'post_action_proof_commands' => [
                'completion_evidence_status' => (string) ($commandsToRerun['completion_evidence_status'] ?? ''),
                'final_evidence_bundle_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-evidence-bundle-status --json',
                'completion_audit_with_canonical_terminal_loop_operational_proof' => (string) ($commandsToRerun['completion_audit_with_canonical_terminal_loop_operational_proof'] ?? ''),
            ],
            'success_check' => $this->nextActionSuccessCheck((string) ($nextStep['artifact'] ?? '')),
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_verifier_status_is_not_passed',
                'stop_if_hash_mismatch',
                'stop_if_real_provider_smoke_is_synthetic',
                'stop_if_completion_audit_still_has_technical_blockers',
            ],
            'non_execution_guarantees' => [
                'shell_packet_does_not_execute_commands',
                'shell_packet_does_not_persist_receipts',
                'shell_packet_does_not_call_provider',
                'shell_packet_does_not_spend_tokens',
                'shell_packet_does_not_dispatch_work',
                'shell_packet_does_not_sign_for_operator',
                'shell_packet_does_not_promote_completion',
            ],
        ];
        $packet['shell_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /** @return list<string> */
    private function commandPlaceholders(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function nextActionSuccessCheck(string $artifact): string
    {
        return match ($artifact) {
            'runtime_promotion_receipt' => 'runtime_gap_matrix.runtime_promotion_receipt.status=passed AND runtime_gap_matrix.all_runtime_y=true',
            'real_provider_smoke' => 'real_provider_smoke.status=passed AND operator_supplied_evidence=true AND real_provider_run_observed_by_operator=true',
            'human_completion_receipt' => 'human_signed_completion_receipt.status=passed AND completion_receipt_hash_matches_payload=true',
            'final_completion_audit' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            default => 'no_operator_action_required',
        };
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json';
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofCanonicalBindingPath().' --json';
    }

    private function terminalLoopOperationalProofCanonicalBindingPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /** @param array<int, mixed> $arguments */
    private function optionalComponent(string $class, array $candidateMethods, array $arguments = []): array
    {
        if (! class_exists($class)) {
            return [
                'available' => false,
                'class' => $class,
                'method' => '',
                'schema' => '',
                'status' => 'missing_component',
                'hash' => '',
                'missing_reason' => 'class_missing',
            ];
        }

        try {
            $instance = $this->instantiate($class);
            foreach ($candidateMethods as $method) {
                if (method_exists($instance, $method)) {
                    $payload = $instance->{$method}(...$arguments);

                    return $this->componentFromPayload($class, $method, is_array($payload) ? $payload : ['status' => 'invalid_payload']);
                }
            }

            return [
                'available' => false,
                'class' => $class,
                'method' => '',
                'schema' => '',
                'status' => 'missing_component',
                'hash' => '',
                'missing_reason' => 'method_missing',
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'class' => $class,
                'method' => '',
                'schema' => '',
                'status' => 'component_exception',
                'hash' => '',
                'missing_reason' => $e->getMessage(),
            ];
        }
    }

    private function instantiate(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfRequiredParameters() === 0) {
            return $reflection->newInstance();
        }

        if ($constructor->getNumberOfRequiredParameters() === 1) {
            $parameter = $constructor->getParameters()[0];
            $type = $parameter->getType();
            if ($type !== null && ! $type->isBuiltin() && (string) $type === AtlasSelfConstructionReadinessService::class) {
                return $reflection->newInstance($this->readiness);
            }
        }

        throw new \RuntimeException('unsupported_constructor');
    }

    /** @param array<string, mixed> $payload */
    private function componentFromPayload(string $class, string $method, array $payload): array
    {
        return [
            'available' => true,
            'class' => $class,
            'method' => $method,
            'schema' => (string) data_get($payload, 'schema_version', ''),
            'status' => (string) data_get($payload, 'status', 'available'),
            'hash' => $this->stableHash($payload),
            'missing_reason' => '',
        ];
    }

    /** @return array<int, string> */
    private function missingRealEvidence(bool $runtimePromotionReady, bool $realProviderSmokeReady, bool $humanCompletionReceiptReady, bool $releaseDossierReady): array
    {
        return array_values(array_filter([
            $runtimePromotionReady ? null : 'runtime_promotion_receipt',
            $realProviderSmokeReady ? null : 'real_provider_claim_to_completion_smoke',
            $humanCompletionReceiptReady ? null : 'human_signed_os_complete_receipt',
            $releaseDossierReady ? null : 'current_release_dossier_snapshot',
        ]));
    }

    /** @return array<int, string> */
    private function readyItems(bool $runtimePromotionReady, bool $realProviderSmokeReady, bool $humanCompletionReceiptReady, bool $releaseDossierReady, bool $completionAuditGreen): array
    {
        return array_values(array_filter([
            $runtimePromotionReady ? 'runtime_promotion' : null,
            $realProviderSmokeReady ? 'real_provider_smoke' : null,
            $humanCompletionReceiptReady ? 'human_completion_receipt' : null,
            $releaseDossierReady ? 'release_dossier' : null,
            $completionAuditGreen ? 'completion_audit' : null,
        ]));
    }

    /** @param array<string, mixed> $completionAudit */
    private function releaseDossierReady(array $completionAudit): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === 'release_dossier_green') {
                return (bool) ($criterion['passed'] ?? false);
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $humanReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @param  array<string, mixed>  $completionReceiptInput
     * @param  array<string, mixed>  $realProviderSmokeInput
     * @return array<string, mixed>
     */
    private function antiFraudEvidence(
        array $completionAudit,
        array $runtimeGapMatrix,
        array $humanReceipt,
        array $realProviderSmoke,
        array $completionReceiptInput,
        array $realProviderSmokeInput,
    ): array {
        return [
            'signed_by' => (string) data_get($humanReceipt, 'signed_by', data_get($completionReceiptInput, 'signed_by', '')),
            'operator_reason' => (string) data_get($humanReceipt, 'reason', data_get($completionReceiptInput, 'reason', '')),
            'before_snapshot_id' => (string) data_get($completionAudit, 'release_dossier_snapshot.snapshot_id', data_get($completionAudit, 'replay_snapshot.snapshot_id', '')),
            'kind' => (string) data_get($realProviderSmoke, 'kind', data_get($realProviderSmokeInput, 'kind', '')),
            'provider_call_observed' => (bool) data_get($realProviderSmoke, 'provider_call_observed', data_get($realProviderSmokeInput, 'provider_call_observed', false)),
            'real_provider_run_observed_by_operator' => (bool) data_get($realProviderSmoke, 'real_provider_run_observed_by_operator', data_get($realProviderSmokeInput, 'real_provider_run_observed_by_operator', false)),
            'provider_response_hash' => (string) data_get($realProviderSmoke, 'provider_response_hash', data_get($realProviderSmokeInput, 'provider_response_hash', '')),
            'cost_event_hash' => (string) data_get($realProviderSmoke, 'cost_event_hash', data_get($realProviderSmokeInput, 'cost_event_hash', '')),
            'signed_dispatch_policy_hash' => (string) data_get($completionAudit, 'signed_dispatch_policy_hash', data_get($realProviderSmokeInput, 'signed_dispatch_policy_hash', '')),
            'final_completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'runtime_autopromotion' => (bool) data_get($runtimeGapMatrix, 'runtime_autopromotion', false),
            'completion_autopromoted' => (bool) data_get($completionAudit, 'completion_autopromoted', false),
            'token_spend_observed' => (bool) data_get($realProviderSmoke, 'token_spend_observed', data_get($realProviderSmokeInput, 'token_spend_observed', false)),
            'dispatch_allowed' => (bool) data_get($completionAudit, 'dispatch_allowed', false),
            'process_started_by_atlas' => (bool) data_get($realProviderSmoke, 'process_started_by_atlas', data_get($realProviderSmokeInput, 'process_started_by_atlas', false)),
            'hash_mutation_detected' => (bool) data_get($completionAudit, 'hash_mutation_detected', false),
            'replay_mismatch' => (bool) data_get($completionAudit, 'replay_mismatch', false),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at']);
        unset($payload['assessed_at'], $payload['audited_at']);
        unset($payload['bundle_identity']['bundle_hash'], $payload['bundle_identity']['bundle_id']);
        unset($payload['machine_status']['final_bundle_hash']);
        unset($payload['promotion_receipt_preimage']['receipt_id']);
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
