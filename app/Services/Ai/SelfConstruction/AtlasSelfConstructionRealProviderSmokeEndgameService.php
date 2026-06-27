<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

final class AtlasSelfConstructionRealProviderSmokeEndgameService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;


    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    public const SCHEMA_VERSION = 'atlas.self_construction.real_provider_smoke_endgame.v1';

    public const MODE = 'read_only_real_provider_smoke_endgame';

    public const BLOCKER_ID = 'end_to_end_real_provider_smoke_green';

    private const CANONICAL_PUBLISHED_SMOKE_PATH = 'atlas/self-construction/operator-submissions/real-provider-smoke.json';

    public function __construct(
        private readonly AtlasSelfConstructionRealProviderSmokeOfflineHarnessService $harness = new AtlasSelfConstructionRealProviderSmokeOfflineHarnessService,
        private readonly AtlasSelfConstructionRealProviderSmokeRunbookService $runbook = new AtlasSelfConstructionRealProviderSmokeRunbookService,
        private readonly AtlasSelfConstructionRealProviderSmokeEvidenceDossierService $dossier = new AtlasSelfConstructionRealProviderSmokeEvidenceDossierService,
        private readonly AtlasSelfConstructionRealProviderSmokeDraftService $draft = new AtlasSelfConstructionRealProviderSmokeDraftService,
        private readonly AtlasSelfConstructionRealProviderSmokeCertificationService $certifier = new AtlasSelfConstructionRealProviderSmokeCertificationService,
        private readonly AtlasSelfConstructionRealProviderSmokeEndgameVerifierService $endgameVerifier = new AtlasSelfConstructionRealProviderSmokeEndgameVerifierService,
        private readonly AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService $ledgerPreflight = new AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService,
        private readonly AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService $exporter = new AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService,
        private readonly AtlasSelfConstructionCompletionAuditBlockerExplainerService $blockerExplainer = new AtlasSelfConstructionCompletionAuditBlockerExplainerService,
    ) {}

    /**
     * @param  array{real_provider_smoke?: array<string, mixed>, persist_completion_evidence?: bool, completion_audit?: array<string, mixed>}  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $providedSmoke = (array) ($options['real_provider_smoke'] ?? []);
        $canonicalSubmission = $providedSmoke === []
            ? $this->loadRealProviderSmokeFromCanonicalSubmission()
            : [];
        $canonicalSmoke = (array) data_get($canonicalSubmission, 'smoke_payload', []);
        $smoke = $providedSmoke !== [] ? $providedSmoke : $canonicalSmoke;
        $smokeSource = match (true) {
            $providedSmoke !== [] => 'operator_supplied_real_provider_smoke',
            $canonicalSmoke !== [] => 'canonical_published_real_provider_smoke',
            default => 'none',
        };
        $persistRequested = (bool) ($options['persist_completion_evidence'] ?? false);
        $completionAudit = (array) ($options['completion_audit'] ?? []);

        $harness = $this->harness->build();
        $runbook = $this->runbook->build($this->smokePreimageTemplate());
        $dossier = $this->dossier->build(['real_provider_smoke' => $smoke]);
        $draft = $this->draft->build($smoke, ['persist_completion_evidence' => false]);
        $certification = $this->certifier->certify($smoke);
        $ledgerResult = $this->ledgerPreflight->preflight($smoke);
        $verifierResult = $this->endgameVerifier->verify($smoke);
        $exporterResult = $this->exporter->build(['persist_export' => false]);
        $blockerExplainerResult = $completionAudit !== []
            ? $this->blockerExplainer->build($completionAudit)
            : ['status' => 'no_completion_audit_supplied'];
        $finalEvidenceBundle = $this->finalEvidenceBundle()->build([
            'real_provider_smoke' => $smoke,
            'completion_audit' => $completionAudit,
        ]);
        $completionAuditResult = $completionAudit !== []
            ? $completionAudit
            : ['status' => 'no_completion_audit_supplied'];

        $status = $this->resolveStatus($smoke, $verifierResult, $certification, $persistRequested);
        $persistenceAttempt = $this->resolvePersistenceAttempt($smoke, $persistRequested, $verifierResult, $certification);
        $operatorSubmissionEnvelope = $this->operatorSubmissionEnvelope($smoke, $verifierResult, $certification);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'blocker_id' => self::BLOCKER_ID,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'persist_completion_evidence_requested' => $persistRequested,
            'smoke_under_review' => [
                'source' => $smokeSource,
                'present' => $smoke !== [],
                'provided_by_cli_payload' => $providedSmoke !== [],
                'provided_by_canonical_submission' => $canonicalSmoke !== [],
                'smoke_hash' => (string) ($smoke['smoke_hash'] ?? ''),
                'provider_run_id' => (string) ($smoke['provider_run_id'] ?? ''),
                'task_packet_id' => (string) ($smoke['task_packet_id'] ?? ''),
            ],
            'canonical_submission_smoke' => $canonicalSubmission,
            'persistence_attempt' => $persistenceAttempt,
            'operator_submission_envelope' => $operatorSubmissionEnvelope,
            'required_evidence_contract' => $this->requiredEvidenceContract(),
            'operator_approval_contract' => $this->operatorApprovalContract(),
            'single_packet_scope_contract' => $this->singlePacketScopeContract(),
            'provider_observation_contract' => $this->providerObservationContract(),
            'token_cost_contract' => $this->tokenCostContract(),
            'work_product_contract' => $this->workProductContract(),
            'continuation_summary_contract' => $this->continuationSummaryContract(),
            'evidence_ledger_contract' => $this->evidenceLedgerContract(),
            'provider_response_contract' => $this->providerResponseContract(),
            'smoke_preimage_template' => $this->smokePreimageTemplate(),
            'offline_harness' => $harness,
            'runbook' => $runbook,
            'evidence_dossier' => $dossier,
            'smoke_draft' => $draft,
            'smoke_certification' => $certification,
            'persistence_preflight' => $ledgerResult,
            'endgame_verifier_result' => $verifierResult,
            'operator_runbook_export' => $exporterResult,
            'blocker_explainer' => $blockerExplainerResult,
            'final_evidence_bundle' => $finalEvidenceBundle,
            'completion_audit' => $completionAuditResult,
            'ordered_operator_steps' => $this->orderedOperatorSteps(),
            'exact_commands' => $this->exactCommands(),
            'stop_conditions' => $this->stopConditions(),
            'anti_cheat_policy' => [
                'no_synthetic_smoke_accepted' => true,
                'no_fixture_substitution_for_real_smoke' => true,
                'atlas_must_not_call_provider' => true,
                'atlas_must_not_spend_tokens' => true,
                'operator_must_observe_real_provider_run' => true,
                'persistence_requires_explicit_flag' => true,
                'reject_persistence_without_operator_submission_envelope' => true,
                'no_os_complete_claim_from_endgame' => true,
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
                'operator_submission_envelope_does_not_write_files_or_receipts' => true,
            ],
            'persistence_allowed_here' => false,
        ];
        $payload['real_provider_smoke_endgame_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @param  array<string, mixed>  $verifier
     * @param  array<string, mixed>  $certification
     */
    private function resolveStatus(array $smoke, array $verifier, array $certification, bool $persistRequested): string
    {
        if ($smoke === []) {
            return 'blocked_operator_real_provider_smoke_required';
        }
        $verifierStatus = (string) ($verifier['status'] ?? '');
        $certStatus = (string) ($certification['status'] ?? '');
        if ($verifierStatus === 'passed' && $certStatus === 'passed') {
            if ($persistRequested) {
                return 'smoke_persisted_completion_evidence_should_be_rerun';
            }

            return 'verifier_passed_ready_for_explicit_persistence';
        }
        if ($verifierStatus === 'passed') {
            return 'verifier_passed_ready_for_explicit_persistence';
        }

        return 'ready_to_verify_smoke_payload';
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @param  array<string, mixed>  $verifier
     * @param  array<string, mixed>  $certification
     * @return array<string, mixed>
     */
    private function resolvePersistenceAttempt(array $smoke, bool $persistRequested, array $verifier, array $certification): array
    {
        if (! $persistRequested) {
            return [
                'requested' => false,
                'attempted' => false,
                'persisted' => false,
                'blocker' => 'persist_completion_evidence_flag_not_supplied',
            ];
        }
        if ($smoke === []) {
            return [
                'requested' => true,
                'attempted' => false,
                'persisted' => false,
                'blocker' => 'no_smoke_payload_supplied',
            ];
        }
        if ((string) ($verifier['status'] ?? '') !== 'passed') {
            return [
                'requested' => true,
                'attempted' => false,
                'persisted' => false,
                'blocker' => 'endgame_verifier_blocked',
            ];
        }
        $persistResult = $this->certifier->persist($smoke);

        return [
            'requested' => true,
            'attempted' => true,
            'persisted' => (bool) ($persistResult['persisted'] ?? false),
            'smoke_path' => (string) ($persistResult['smoke_path'] ?? ''),
            'certifier_status' => (string) ($persistResult['status'] ?? ''),
            'persistence_blocker' => (string) ($persistResult['persistence_blocker'] ?? ''),
            'certifier_result' => $persistResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $smoke
     * @param  array<string, mixed>  $verifier
     * @param  array<string, mixed>  $certification
     * @return array<string, mixed>
     */
    private function operatorSubmissionEnvelope(array $smoke, array $verifier, array $certification): array
    {
        $verifierPassed = (string) data_get($verifier, 'status') === 'passed';
        $certificationPassed = (string) data_get($certification, 'status') === 'passed';
        $smokeJson = $smoke === []
            ? ''
            : (string) json_encode($this->ksortRecursive($smoke), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $status = match (true) {
            $smoke === [] => 'blocked_until_operator_smoke_payload_exists',
            ! $verifierPassed => 'blocked_until_endgame_verifier_passes',
            ! $certificationPassed => 'blocked_until_smoke_certification_passes',
            default => 'ready_for_explicit_operator_persistence',
        };

        $envelope = [
            'schema_version' => 'atlas.self_construction.real_provider_smoke_operator_submission_envelope.v1',
            'mode' => 'read_only_operator_submission_envelope',
            'status' => $status,
            'smoke_payload_under_review' => $smoke,
            'smoke_json_sha256' => $smokeJson === '' ? '' : hash('sha256', $smokeJson),
            'smoke_hash' => (string) ($smoke['smoke_hash'] ?? ''),
            'verifier_status' => (string) data_get($verifier, 'status', ''),
            'certification_status' => (string) data_get($certification, 'status', ''),
            'can_persist_after_operator_review' => $status === 'ready_for_explicit_operator_persistence',
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'pre_persist_operator_checks' => [
                'smoke_payload_is_from_real_operator_observed_provider_run',
                'smoke_hash_matches_canonical_payload_hash',
                'operator_approval_receipt_hash_is_current',
                'provider_run_id_and_task_packet_id_are_non_placeholder',
                'provider_response_hash_cost_event_hash_work_product_hash_continuation_hash_and_ledger_hash_are_current',
                'provider_called_by_atlas_token_spent_by_atlas_dispatch_allowed_adapter_execution_allowed_self_programming_allowed_are_false',
                'persistence_flag_is_explicitly_present',
                'completion_audit_must_be_rerun_after_persistence',
            ],
            'post_persistence_rerun_commands' => [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
                'completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'human_completion_receipt_closure_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --json',
            ],
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'non_execution_guarantees' => [
                'envelope_does_not_call_provider' => true,
                'envelope_does_not_spend_tokens' => true,
                'envelope_does_not_dispatch' => true,
                'envelope_does_not_persist_smoke' => true,
                'envelope_does_not_promote_completion' => true,
                'envelope_does_not_sign_for_operator' => true,
            ],
        ];
        $envelope['operator_submission_envelope_hash'] = $this->stableHash($envelope);

        return $envelope;
    }

    /** @return array<string, mixed> */
    private function requiredEvidenceContract(): array
    {
        return [
            'identity_fields' => array_keys(AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::REQUIRED_IDENTITY_FIELDS),
            'hash_fields' => array_keys(AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::REQUIRED_HASH_FIELDS),
            'observation_flags' => array_keys(AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::REQUIRED_OBSERVATION_FLAGS),
            'forbidden_flags' => AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::FORBIDDEN_FLAGS,
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
    private function providerObservationContract(): array
    {
        return [
            'rule' => 'A human operator must observe the live provider call. Atlas must not call the provider.',
            'required_flag' => 'provider_call_observed',
            'forbidden_flag' => 'provider_called_by_atlas',
            'stop_condition' => 'stop_if_provider_call_not_observed_by_operator',
        ];
    }

    /** @return array<string, mixed> */
    private function tokenCostContract(): array
    {
        return [
            'rule' => 'Operator must capture the cost/usage event from the observed run.',
            'required_flag' => 'token_spend_observed',
            'required_hash_field' => 'cost_event_hash',
            'forbidden_flag' => 'token_spent_by_atlas',
            'stop_condition' => 'stop_if_token_spend_not_observed_or_cost_event_hash_invalid',
        ];
    }

    /** @return array<string, mixed> */
    private function workProductContract(): array
    {
        return [
            'rule' => 'Work product manifest must be captured and hashed from the observed run.',
            'required_flag' => 'work_product_collected',
            'required_hash_field' => 'work_product_manifest_hash',
            'stop_condition' => 'stop_if_work_product_manifest_hash_invalid',
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

    /** @return array<string, mixed> */
    private function smokePreimageTemplate(): array
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
    private function orderedOperatorSteps(): array
    {
        return [
            'review_offline_harness',
            'sign_operator_approval_for_single_packet',
            'prepare_claim_to_completion_task_packet',
            'run_real_provider_path_outside_read_only_surface',
            'collect_provider_run_id',
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
        ];
    }

    /** @return array<string, string> */
    private function exactCommands(): array
    {
        return [
            'review_offline_harness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
            'draft_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'ledger_preflight' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'endgame_verifier' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-verifier-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            'export_runbook' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-operator-runbook-exporter-status --json',
            'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'rerun_completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofCanonicalBindingPath(),
            'rerun_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'rerun_completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        ];
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
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

    /** @return list<string> */
    private function stopConditions(): array
    {
        return [
            'stop_if_atlas_kernel_is_calling_provider',
            'stop_if_more_than_one_packet_exercised',
            'stop_if_observer_is_placeholder_or_codex',
            'stop_if_synthetic_marker_present',
            'stop_if_any_forbidden_flag_true',
            'stop_if_endgame_verifier_diagnostics_non_empty',
            'never_persist_without_explicit_flag',
            'stop_if_blocker_still_failing',
        ];
    }

    private function finalEvidenceBundle(): AtlasSelfConstructionFinalEvidenceBundleService
    {
        return app(AtlasSelfConstructionFinalEvidenceBundleService::class);
    }

    /** @return array<string, mixed> */
    private function loadRealProviderSmokeFromCanonicalSubmission(): array
    {
        if (! Storage::disk('local')->exists(self::CANONICAL_PUBLISHED_SMOKE_PATH)) {
            return [
                'status' => 'not_found',
                'submission_path' => self::CANONICAL_PUBLISHED_SMOKE_PATH,
                'smoke_payload' => [],
                'violations' => [],
            ];
        }

        try {
            $decoded = json_decode(Storage::disk('local')->get(self::CANONICAL_PUBLISHED_SMOKE_PATH), true, flags: JSON_THROW_ON_ERROR);
            $payload = is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            $payload = [];
        }

        if ($payload === []) {
            return [
                'status' => 'blocked',
                'submission_path' => self::CANONICAL_PUBLISHED_SMOKE_PATH,
                'smoke_payload' => [],
                'violations' => ['canonical_real_provider_smoke_submission_invalid_json_or_empty'],
            ];
        }

        return [
            'status' => 'loaded_for_endgame_review',
            'submission_path' => self::CANONICAL_PUBLISHED_SMOKE_PATH,
            'smoke_payload' => $payload,
            'violations' => [],
            'non_execution_guarantees' => [
                'canonical_submission_loader_reads_only',
                'canonical_submission_loader_does_not_mark_submission_as_persisted_evidence',
                'canonical_submission_loader_does_not_call_provider',
                'canonical_submission_loader_does_not_spend_tokens',
                'canonical_submission_loader_does_not_persist_smoke',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['real_provider_smoke_endgame_hash']);
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
