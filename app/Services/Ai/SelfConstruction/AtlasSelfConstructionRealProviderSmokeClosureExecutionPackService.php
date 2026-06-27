<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;

final class AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_closure_execution_pack.v1';

    public const MODE = 'read_only_real_provider_smoke_closure_execution_pack';

    public const BLOCKER_ID = 'end_to_end_real_provider_smoke_green';

    public function __construct(
        private readonly AtlasSelfConstructionRealProviderSmokeRunbookService $runbook = new AtlasSelfConstructionRealProviderSmokeRunbookService,
        private readonly AtlasSelfConstructionRealProviderSmokeOfflineHarnessService $harness = new AtlasSelfConstructionRealProviderSmokeOfflineHarnessService,
        private readonly AtlasSelfConstructionRealProviderSmokeDraftService $draft = new AtlasSelfConstructionRealProviderSmokeDraftService,
        private readonly AtlasSelfConstructionRealProviderSmokeEvidenceDossierService $dossier = new AtlasSelfConstructionRealProviderSmokeEvidenceDossierService,
        private readonly AtlasSelfConstructionRealProviderSmokeCertificationService $certifier = new AtlasSelfConstructionRealProviderSmokeCertificationService,
        private readonly AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService $preSubmission = new AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService,
        private readonly AtlasSelfConstructionRealProviderSmokeOperatorChecklistService $checklist = new AtlasSelfConstructionRealProviderSmokeOperatorChecklistService,
    ) {}

    private function bundleService(): AtlasSelfConstructionFinalEvidenceBundleService
    {
        return app(AtlasSelfConstructionFinalEvidenceBundleService::class);
    }

    /**
     * @param  array{real_provider_smoke?: array<string, mixed>, completion_audit?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $smokeInput = (array) ($options['real_provider_smoke'] ?? []);
        $completionAudit = (array) ($options['completion_audit'] ?? []);

        $runbook = $this->runbook->build($this->providerSmokeTemplate());
        $harness = $this->harness->build();
        $draft = $this->draft->build($smokeInput, ['persist_completion_evidence' => false]);
        $dossier = $this->dossier->build(['real_provider_smoke' => $smokeInput]);
        $certification = $this->certifier->certify($smokeInput);
        $preSubmissionResult = $this->preSubmission->verify($smokeInput);
        $operatorChecklist = $this->checklist->build();
        $blockerExplainer = $completionAudit !== []
            ? (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit)
            : ['status' => 'no_completion_audit_supplied'];
        $finalEvidenceBundle = $this->bundleService()->build([
            'real_provider_smoke' => $smokeInput,
            'completion_audit' => $completionAudit,
        ]);
        $completionEvidenceStatus = (array) data_get($finalEvidenceBundle, 'completion_evidence_status', []);

        $status = $this->resolveStatus($smokeInput, $preSubmissionResult, $certification);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'blocker_id' => self::BLOCKER_ID,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'provider_smoke_template' => $this->providerSmokeTemplate(),
            'required_evidence_fields' => $this->requiredEvidenceFields(),
            'required_hash_fields' => $this->requiredHashFields(),
            'required_observation_flags' => $this->requiredObservationFlags(),
            'forbidden_flags' => $this->forbiddenFlags(),
            'operator_approval_contract' => $this->operatorApprovalContract(),
            'single_packet_scope_contract' => $this->singlePacketScopeContract(),
            'work_product_collection_contract' => $this->workProductCollectionContract(),
            'cost_event_contract' => $this->costEventContract(),
            'continuation_summary_contract' => $this->continuationSummaryContract(),
            'evidence_ledger_contract' => $this->evidenceLedgerContract(),
            'provider_response_contract' => $this->providerResponseContract(),
            'runbook' => $runbook,
            'offline_harness' => $harness,
            'draft_payload' => $draft,
            'evidence_dossier' => $dossier,
            'completion_evidence_status' => $completionEvidenceStatus,
            'blocker_explainer' => $blockerExplainer,
            'final_evidence_bundle' => $finalEvidenceBundle,
            'certification_result' => $certification,
            'pre_submission_verifier_result' => $preSubmissionResult,
            'operator_checklist' => $operatorChecklist,
            'ordered_operator_steps' => $this->orderedOperatorSteps(),
            'exact_commands' => $this->exactCommands(),
            'anti_cheat_policy' => [
                'no_synthetic_smoke_accepted' => true,
                'atlas_must_not_call_provider' => true,
                'atlas_must_not_spend_tokens' => true,
                'operator_must_observe_real_provider_run' => true,
                'persistence_requires_explicit_flag' => true,
                'no_os_complete_claim_from_pack' => true,
            ],
            'non_execution_guarantees' => [
                'does_not_call_provider' => true,
                'does_not_spend_tokens' => true,
                'does_not_dispatch' => true,
                'does_not_start_process' => true,
                'does_not_persist_smoke' => true,
                'does_not_promote_completion' => true,
                'does_not_enable_runtime' => true,
                'does_not_sign_for_operator' => true,
            ],
            'persistence_allowed_here' => false,
        ];
        $payload['closure_pack_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $smokeInput
     * @param  array<string, mixed>  $preSubmissionResult
     * @param  array<string, mixed>  $certification
     */
    private function resolveStatus(array $smokeInput, array $preSubmissionResult, array $certification): string
    {
        if ($smokeInput === []) {
            return 'blocked_operator_real_provider_smoke_required';
        }
        $preStatus = (string) ($preSubmissionResult['status'] ?? '');
        $certStatus = (string) ($certification['status'] ?? '');
        if ($certStatus === 'passed') {
            return 'real_provider_smoke_verified';
        }
        if ($preStatus === 'passed') {
            return 'ready_to_persist_real_provider_smoke';
        }

        return 'ready_to_verify_operator_smoke_payload';
    }

    /** @return array<string, mixed> */
    private function providerSmokeTemplate(): array
    {
        return [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => '<operator_observed_provider_run_id>',
            'task_packet_id' => '<operator_observed_task_packet_id>',
            'observed_by' => '<operator_or_reviewer>',
            'approval_reason' => 'Operator approved and observed one real provider claim-to-completion smoke.',
            'smoke_hash' => '<operator_computed_64_hex_smoke_hash>',
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
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];
    }

    /** @return list<string> */
    private function requiredEvidenceFields(): array
    {
        return [
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
        ];
    }

    /** @return list<string> */
    private function requiredHashFields(): array
    {
        return [
            'smoke_hash',
            'operator_approval_receipt_hash',
            'evidence_ledger_hash',
            'work_product_manifest_hash',
            'cost_event_hash',
            'continuation_summary_hash',
            'provider_response_hash',
        ];
    }

    /** @return list<string> */
    private function requiredObservationFlags(): array
    {
        return [
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ];
    }

    /** @return list<string> */
    private function forbiddenFlags(): array
    {
        return [
            'provider_called_by_atlas',
            'token_spent_by_atlas',
            'dispatch_allowed',
            'adapter_execution_allowed',
            'self_programming_allowed',
            'completion_claim_promoted_without_receipt',
        ];
    }

    /** @return array<string, mixed> */
    private function operatorApprovalContract(): array
    {
        return [
            'rule' => 'Operator approval receipt must exist and be hashed before any provider call.',
            'required_hash_field' => 'operator_approval_receipt_hash',
            'placeholder_signers_rejected' => ['<operator>', 'codex', 'codex-autosigned', 'assistant', 'system', 'claude'],
            'stop_condition' => 'no_provider_call_without_operator_approval_receipt_hash',
        ];
    }

    /** @return array<string, mixed> */
    private function singlePacketScopeContract(): array
    {
        return [
            'rule' => 'Exactly one task packet must be exercised claim-to-completion through the real provider path.',
            'required_fields' => ['task_packet_id', 'provider_run_id'],
            'stop_condition' => 'stop_if_more_than_one_packet_exercised',
        ];
    }

    /** @return array<string, mixed> */
    private function workProductCollectionContract(): array
    {
        return [
            'rule' => 'Work product manifest must be collected and hashed from the observed run.',
            'required_hash_field' => 'work_product_manifest_hash',
            'required_observation_flag' => 'work_product_collected',
            'stop_condition' => 'stop_if_work_product_manifest_hash_invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function costEventContract(): array
    {
        return [
            'rule' => 'Cost event must be collected from the observed run (operator-side accounting).',
            'required_hash_field' => 'cost_event_hash',
            'required_observation_flag' => 'token_spend_observed',
            'stop_condition' => 'stop_if_cost_event_hash_invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function continuationSummaryContract(): array
    {
        return [
            'rule' => 'Continuation summary must be captured for the observed run.',
            'required_hash_field' => 'continuation_summary_hash',
            'stop_condition' => 'stop_if_continuation_summary_hash_invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceLedgerContract(): array
    {
        return [
            'rule' => 'Evidence ledger hash must be written or attached as part of the smoke evidence.',
            'required_hash_field' => 'evidence_ledger_hash',
            'stop_condition' => 'stop_if_evidence_ledger_hash_invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function providerResponseContract(): array
    {
        return [
            'rule' => 'Provider response payload must be hashed deterministically for replay.',
            'required_hash_field' => 'provider_response_hash',
            'stop_condition' => 'stop_if_provider_response_hash_invalid',
        ];
    }

    /** @return list<string> */
    private function orderedOperatorSteps(): array
    {
        return [
            'review_offline_harness',
            'approve_single_packet_scope',
            'run_real_provider_smoke_outside_read_only_surface',
            'collect_provider_response_hash',
            'collect_cost_event_hash',
            'collect_work_product_manifest_hash',
            'collect_continuation_summary_hash',
            'collect_evidence_ledger_hash',
            'build_smoke_preimage',
            'compute_smoke_hash',
            'verify_smoke_payload',
            'persist_with_explicit_flag_only',
            'rerun_completion_evidence_status',
            'rerun_completion_audit',
            'refresh_terminal_loop_operational_proof',
            'persist_terminal_loop_operational_proof_binding',
            'rerun_completion_audit_with_terminal_loop_operational_proof',
            'rerun_completion_audit_with_canonical_terminal_loop_operational_proof',
        ];
    }

    /** @return array<string, string> */
    private function exactCommands(): array
    {
        return [
            'review_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
            'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'verify_pre_submission' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-pre-submission-verifier-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'rerun_completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'rerun_completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
            'persist_terminal_loop_operational_proof_binding' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
            'rerun_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'rerun_completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'rerun_completion_audit_diagnostic' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];
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
        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$this->terminalLoopOperationalProofCanonicalBindingPath().' --json';
    }

    private function terminalLoopOperationalProofCanonicalBindingPath(): string
    {
        return 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['closure_pack_hash']);
        $this->stripVolatileTimestamps($payload);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $payload */
    private function stripVolatileTimestamps(array &$payload): void
    {
        $keys = ['generated_at', 'verified_at', 'certified_at', 'persisted_at'];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $this->stripVolatileTimestamps($payload[$key]);
            }
            if (in_array((string) $key, $keys, true)) {
                unset($payload[$key]);
            }
        }
    }

    /** @param array<string, mixed> $value */
}
