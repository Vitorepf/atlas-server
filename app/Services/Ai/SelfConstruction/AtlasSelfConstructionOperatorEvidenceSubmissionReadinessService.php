<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Operator Evidence Submission Readiness v1.
 *
 * Takes optional operator-supplied payloads (runtime promotion receipt, real
 * provider smoke, human completion receipt) and reports a strictly read-only
 * diagnostic of what is missing before the operator can rerun the completion
 * audit. It uses the canonical verifiers as a library; it never persists,
 * never calls providers, never spends tokens.
 */
final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_submission_readiness.v1';

    public const MODE = 'read_only_operator_evidence_submission_readiness';

    private const STORAGE_DISK = 'local';

    private const REQUIRED_TERMINAL_LOOP_END_TO_END_CONTRACT_CAPABILITIES = [
        'auto_replenishment',
        'validation',
        'leases',
        'evidence',
        'retomada',
        'lane_isolation',
        'cycle_supervision',
        'operator_handoff',
    ];

    /** @var array<string, string> */
    private const CANONICAL_SUBMISSION_PATHS = [
        'runtime_promotion_receipt' => 'atlas/self-construction/operator-submissions/runtime-promotion.json',
        'real_provider_smoke' => 'atlas/self-construction/operator-submissions/real-provider-smoke.json',
        'human_completion_receipt' => 'atlas/self-construction/operator-submissions/completion-receipt.json',
    ];

    /** @var array<string, string> */
    private const CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS = [
        'runtime_promotion_receipt' => 'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
        'real_provider_smoke' => 'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
        'human_completion_receipt' => 'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
    ];

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    private ?OperatorEvidenceSubmissionInputLoader $submissionInputLoaderInstance = null;

    private ?OperatorEvidenceDraftHashFinalizationSummarizer $draftHashFinalizationSummarizerInstance = null;

    private ?OperatorEvidenceSubmissionCommandSurfaceCollector $commandSurfaceCollectorInstance = null;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $operatorDraftWorkspacePath = (string) ($options['operator_draft_workspace_path'] ?? '');
        $workspaceInput = $this->loadDraftWorkspaceInput($operatorDraftWorkspacePath);
        $canonicalSubmissionInput = $this->loadCanonicalSubmissionInput();
        $draftHashFinalization = $operatorDraftWorkspacePath === ''
            ? $this->draftHashFinalizationNotRequested()
            : (new AtlasSelfConstructionOperatorEvidenceDraftHashFinalizerService)->finalize([
                'operator_draft_workspace_path' => $operatorDraftWorkspacePath,
                'write_computed_operator_draft_hashes' => false,
            ]);
        $workspacePayloads = (array) data_get($workspaceInput, 'payloads', []);
        $canonicalSubmissionPayloads = (array) data_get($canonicalSubmissionInput, 'payloads', []);

        $runtimeReceipt = $this->payloadOrWorkspaceOrCanonicalSubmission(
            explicit: (array) ($options['runtime_promotion_receipt'] ?? []),
            workspacePayloads: $workspacePayloads,
            canonicalSubmissionPayloads: $canonicalSubmissionPayloads,
            workspaceKey: 'runtime_promotion_receipt',
        );
        $realProviderSmoke = $this->payloadOrWorkspaceOrCanonicalSubmission(
            explicit: (array) ($options['real_provider_smoke'] ?? []),
            workspacePayloads: $workspacePayloads,
            canonicalSubmissionPayloads: $canonicalSubmissionPayloads,
            workspaceKey: 'real_provider_smoke',
        );
        $completionReceipt = $this->payloadOrWorkspaceOrCanonicalSubmission(
            explicit: (array) ($options['completion_receipt'] ?? []),
            workspacePayloads: $workspacePayloads,
            canonicalSubmissionPayloads: $canonicalSubmissionPayloads,
            workspaceKey: 'human_completion_receipt',
        );

        $completionAudit = (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit($options);
        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->readiness))->matrix();
        $rows = (array) data_get($runtimeGapMatrix, 'rows', []);
        $expectedRuntimeGapMatrixHashForPromotionReceipt = (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', '');
        $runtimePromotionBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', '');
        $runtimePromotionClosureBasisHash = (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', '');

        $runtimeVerification = $runtimeReceipt === []
            ? $this->emptyVerification('runtime_promotion_receipt_not_supplied')
            : (new AtlasSelfConstructionRuntimePromotionReceiptService)->verify(
                receipt: $runtimeReceipt,
                rows: $rows,
                expectedRuntimePromotionBasisHash: $runtimePromotionBasisHash,
                expectedRuntimeGapMatrixHash: $expectedRuntimeGapMatrixHashForPromotionReceipt,
                expectedRuntimePromotionClosureBasisHash: $runtimePromotionClosureBasisHash,
                loadLatestWhenEmpty: false,
            );

        $smokeVerification = $realProviderSmoke === []
            ? $this->emptyVerification('real_provider_smoke_not_supplied')
            : (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($realProviderSmoke);

        $humanContext = $this->humanContextFromOptions($options, $runtimeGapMatrix, $runtimeReceipt, $realProviderSmoke, $completionAudit);
        $humanVerification = $completionReceipt === []
            ? $this->emptyVerification('human_completion_receipt_not_supplied')
            : (new AtlasSelfConstructionHumanCompletionReceiptVerifierService)->verify($completionReceipt, $humanContext);

        $hashComposition = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => $runtimeReceipt,
            'real_provider_smoke' => $realProviderSmoke,
            'completion_receipt' => $completionReceipt,
        ]);

        $runtimePassed = (string) ($runtimeVerification['status'] ?? '') === 'passed';
        $smokePassed = (string) ($smokeVerification['status'] ?? '') === 'passed';
        $humanPassed = (string) ($humanVerification['status'] ?? '') === 'passed';

        $humanReceiptOutOfOrder = $completionReceipt !== [] && (! $runtimePassed || ! $smokePassed);

        $diagnostics = [
            'runtime_promotion_receipt' => $this->diagnosticRow(
                supplied: $runtimeReceipt !== [],
                verification: $runtimeVerification,
                composedHash: (string) data_get($hashComposition, 'runtime_promotion_receipt.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'runtime_promotion_receipt.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'runtime_promotion_receipt.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'],
            ),
            'real_provider_smoke' => $this->diagnosticRow(
                supplied: $realProviderSmoke !== [],
                verification: $smokeVerification,
                composedHash: (string) data_get($hashComposition, 'real_provider_smoke.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'real_provider_smoke.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'real_provider_smoke.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['provider_called_by_atlas', 'token_spent_by_atlas', 'dispatch_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_claim_promoted_without_receipt'],
            ),
            'human_completion_receipt' => $this->diagnosticRow(
                supplied: $completionReceipt !== [],
                verification: $humanVerification,
                composedHash: (string) data_get($hashComposition, 'human_completion_receipt.computed_hash', ''),
                placeholders: (array) data_get($hashComposition, 'human_completion_receipt.placeholder_fields', []),
                forbiddenFlagsTrue: (array) data_get($hashComposition, 'human_completion_receipt.runtime_enabling_flags_true', []),
                forbiddenFlagList: ['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed', 'completion_autopromoted'],
            ),
        ];

        if ($humanReceiptOutOfOrder) {
            $diagnostics['human_completion_receipt']['errors'][] = 'human_completion_receipt_supplied_before_runtime_and_smoke_green';
            $diagnostics['human_completion_receipt']['ready'] = false;
        }

        $missingProviderEvidence = $this->missingProviderEvidence($realProviderSmoke);
        if ($missingProviderEvidence !== []) {
            $diagnostics['real_provider_smoke']['errors'] = array_values(array_unique(array_merge(
                (array) $diagnostics['real_provider_smoke']['errors'],
                $missingProviderEvidence,
            )));
            $diagnostics['real_provider_smoke']['ready'] = false;
        }

        $staleContextHashes = $this->staleContextHashes(
            runtimeReceipt: $runtimeReceipt,
            currentRuntimeGapMatrixHashForPromotionReceipt: $expectedRuntimeGapMatrixHashForPromotionReceipt,
            currentRuntimePromotionBasisHash: $runtimePromotionBasisHash,
            currentRuntimePromotionClosureBasisHash: $runtimePromotionClosureBasisHash,
        );
        $draftWorkspaceRefresh = $this->draftWorkspaceRefreshDecision(
            workspaceInput: $workspaceInput,
            staleContextHashes: $staleContextHashes,
            runtimeGapMatrix: $runtimeGapMatrix,
        );

        $nextRequired = match (true) {
            $runtimeReceipt === [] => 'runtime_promotion_receipt',
            ! $runtimePassed => 'runtime_promotion_receipt',
            $realProviderSmoke === [] => 'real_provider_smoke',
            ! $smokePassed => 'real_provider_smoke',
            $completionReceipt === [] => 'human_completion_receipt',
            ! $humanPassed => 'human_completion_receipt',
            default => 'rerun_completion_audit',
        };

        $status = match ($nextRequired) {
            'rerun_completion_audit' => 'ready_for_completion_audit_rerun',
            default => $runtimeReceipt === [] && $realProviderSmoke === [] && $completionReceipt === []
                ? 'no_input'
                : 'incomplete',
        };

        $operatorSubmissionEnvelopes = $this->operatorSubmissionEnvelopes(
            runtimeReceipt: $runtimeReceipt,
            realProviderSmoke: $realProviderSmoke,
            completionReceipt: $completionReceipt,
            diagnostics: $diagnostics,
            hashComposition: (array) $hashComposition,
            humanContext: $humanContext,
            nextRequired: $nextRequired,
        );
        $canonicalSubmissionPersistencePlan = $this->canonicalSubmissionPersistencePlan(
            canonicalSubmissionInput: $canonicalSubmissionInput,
            diagnostics: $diagnostics,
            explicitPayloadSupplied: (array) ($options['runtime_promotion_receipt'] ?? []) !== []
                || (array) ($options['real_provider_smoke'] ?? []) !== []
                || (array) ($options['completion_receipt'] ?? []) !== [],
            workspacePayloadSupplied: (string) data_get($workspaceInput, 'status', '') === 'loaded_for_read_only_submission_readiness',
            persistedEvidenceState: $this->persistedEvidenceState($runtimeGapMatrix),
        );
        $operatorNextAction = $this->operatorNextAction(
            nextRequired: $nextRequired,
            nextRequiredCommand: $this->nextRequiredCommand($nextRequired),
            operatorSubmissionEnvelopes: $operatorSubmissionEnvelopes,
            canonicalSubmissionPersistencePlan: $canonicalSubmissionPersistencePlan,
        );
        $operatorEvidenceSequenceIntegrity = $this->operatorEvidenceSequenceIntegrity(
            diagnostics: $diagnostics,
            canonicalSubmissionPersistencePlan: $canonicalSubmissionPersistencePlan,
            persistedEvidenceState: $this->persistedEvidenceState($runtimeGapMatrix),
            nextRequired: $nextRequired,
        );
        $operatorCompletionProofBundle = $this->operatorCompletionProofBundle(
            diagnostics: $diagnostics,
            operatorSubmissionEnvelopes: $operatorSubmissionEnvelopes,
            canonicalSubmissionPersistencePlan: $canonicalSubmissionPersistencePlan,
            persistedEvidenceState: $this->persistedEvidenceState($runtimeGapMatrix),
            completionAudit: $completionAudit,
            operatorNextAction: $operatorNextAction,
        );
        $operatorEvidenceClosureRunbook = $this->operatorEvidenceClosureRunbook(
            completionAudit: $completionAudit,
            operatorNextAction: $operatorNextAction,
            operatorCompletionProofBundle: $operatorCompletionProofBundle,
            canonicalSubmissionPersistencePlan: $canonicalSubmissionPersistencePlan,
        );
        $closureArtifactSequence = $this->closureArtifactSequence(
            runtimePassed: $runtimePassed,
            smokePassed: $smokePassed,
            humanPassed: $humanPassed,
            completionAuditReady: (string) data_get($completionAudit, 'status') === 'complete'
                && (bool) data_get($completionAudit, 'completion_allowed', false),
        );
        $nextActionGraph = $this->nextActionGraph(
            runtimePassed: $runtimePassed,
            smokePassed: $smokePassed,
            humanPassed: $humanPassed,
            diagnostics: $diagnostics,
        );
        $promptToArtifactChecklist = $this->promptToArtifactChecklist($closureArtifactSequence);
        $externalCompletionClaimPolicy = $this->externalCompletionClaimPolicy(
            completionAudit: $completionAudit,
            closureArtifactSequence: $closureArtifactSequence,
            nextRequired: $nextRequired,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => false,
            'completion_claim_allowed' => false,
            'next_required' => $nextRequired,
            'next_required_command' => $this->nextRequiredCommand($nextRequired),
            'draft_workspace_input' => $workspaceInput,
            'canonical_submission_input' => $canonicalSubmissionInput,
            'draft_hash_finalization' => $this->draftHashFinalizationSummary($draftHashFinalization, $operatorDraftWorkspacePath),
            'draft_hash_finalization_required' => $this->draftHashFinalizationRequired($draftHashFinalization),
            'diagnostics' => $diagnostics,
            'operator_submission_envelopes' => $operatorSubmissionEnvelopes,
            'canonical_submission_persistence_plan' => $canonicalSubmissionPersistencePlan,
            'operator_next_action' => $operatorNextAction,
            'operator_evidence_sequence_integrity' => $operatorEvidenceSequenceIntegrity,
            'operator_completion_proof_bundle' => $operatorCompletionProofBundle,
            'operator_evidence_closure_runbook' => $operatorEvidenceClosureRunbook,
            'external_completion_claim_policy' => $externalCompletionClaimPolicy,
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => $this->stableHash($closureArtifactSequence),
            'next_action_graph' => $nextActionGraph,
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => count(array_filter($promptToArtifactChecklist, static fn (array $row): bool => (bool) $row['passed'])),
            'prompt_to_artifact_checklist_hash' => $this->stableHash($promptToArtifactChecklist),
            'runtime_promotion_receipt_passed' => $runtimePassed,
            'real_provider_smoke_passed' => $smokePassed,
            'human_completion_receipt_passed' => $humanPassed,
            'human_receipt_out_of_order' => $humanReceiptOutOfOrder,
            'stale_context_hashes' => $staleContextHashes,
            'draft_workspace_refresh' => $draftWorkspaceRefresh,
            'draft_workspace_refresh_required' => (bool) data_get($draftWorkspaceRefresh, 'required', false),
            'hash_composition' => [
                'composer_hash' => (string) data_get($hashComposition, 'composer_hash', ''),
                'status' => (string) data_get($hashComposition, 'status', ''),
                'runtime_promotion_receipt_hash' => (string) data_get($hashComposition, 'runtime_promotion_receipt.computed_hash', ''),
                'real_provider_smoke_hash' => (string) data_get($hashComposition, 'real_provider_smoke.computed_hash', ''),
                'human_completion_receipt_hash' => (string) data_get($hashComposition, 'human_completion_receipt.computed_hash', ''),
            ],
            'current_runtime_context' => [
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => $expectedRuntimeGapMatrixHashForPromotionReceipt,
                'runtime_promotion_basis_hash' => $runtimePromotionBasisHash,
                'runtime_promotion_closure_basis_hash' => $runtimePromotionClosureBasisHash,
                'runtime_gap_count' => (int) data_get($runtimeGapMatrix, 'runtime_gap_count', 0),
            ],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'operator_evidence_submission_readiness_does_not_persist_receipts',
                'operator_evidence_submission_readiness_does_not_call_provider',
                'operator_evidence_submission_readiness_does_not_spend_tokens',
                'operator_evidence_submission_readiness_does_not_dispatch_work',
                'operator_evidence_submission_readiness_does_not_enable_runtime',
                'operator_evidence_submission_readiness_does_not_sign_for_operator',
                'operator_evidence_submission_readiness_does_not_promote_completion',
                'operator_evidence_submission_readiness_does_not_accept_external_completion_claims',
                'operator_evidence_submission_readiness_does_not_persist_operator_submission_envelopes',
                'operator_evidence_submission_readiness_reads_draft_workspace_without_marking_it_as_evidence',
                'operator_evidence_submission_readiness_reads_canonical_submission_files_without_marking_them_as_persisted_evidence',
            ],
        ];
        $payload['operator_command_surface_integrity'] = $this->operatorCommandSurfaceIntegrity($payload);
        $payload['submission_readiness_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * Verifies that every `atlas:ai:self-construction` command string exposed
     * by this readiness payload references command-line options that exist on
     * the real Artisan command. This stays read-only: it inspects the command
     * definition but never executes any returned operator command.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function operatorCommandSurfaceIntegrity(array $payload): array
    {
        return $this->commandSurfaceCollector()->operatorCommandSurfaceIntegrity($payload);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, string>
     */
    private function collectOperatorCommands(array $value, string $path = 'payload'): array
    {
        return $this->commandSurfaceCollector()->collectOperatorCommands($value, $path);
    }

    /**
     * @return list<string>
     */
    private function extractCommandOptions(string $command): array
    {
        return $this->commandSurfaceCollector()->extractCommandOptions($command);
    }

    /**
     * @return list<string>
     */
    private function selfConstructionCommandOptions(): array
    {
        return $this->commandSurfaceCollector()->selfConstructionCommandOptions();
    }

    /**
     * @return list<string>
     */
    private function legacySelfConstructionCommandAliases(): array
    {
        return $this->commandSurfaceCollector()->legacySelfConstructionCommandAliases();
    }

    private function commandSurfaceCollector(): OperatorEvidenceSubmissionCommandSurfaceCollector
    {
        return $this->commandSurfaceCollectorInstance ??= new OperatorEvidenceSubmissionCommandSurfaceCollector;
    }

    /**
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @param  array<string, mixed>  $canonicalSubmissionPersistencePlan
     * @param  array<string, mixed>  $persistedEvidenceState
     * @return array<string, mixed>
     */
    private function operatorEvidenceSequenceIntegrity(array $diagnostics, array $canonicalSubmissionPersistencePlan, array $persistedEvidenceState, string $nextRequired): array
    {
        $artifactOrder = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ];
        $stepIndexes = array_flip($artifactOrder);
        $currentStepIndex = $nextRequired === 'rerun_completion_audit'
            ? count($artifactOrder)
            : (int) ($stepIndexes[$nextRequired] ?? 0);

        $artifactStates = [];
        $violations = [];

        foreach ($artifactOrder as $index => $artifact) {
            $diagnostic = (array) ($diagnostics[$artifact] ?? []);
            $persisted = (array) data_get($persistedEvidenceState, $artifact, []);
            $previousArtifacts = array_slice($artifactOrder, 0, $index);
            $previousArtifactsGreen = true;
            $previousArtifactsPersisted = true;

            foreach ($previousArtifacts as $previousArtifact) {
                $previousDiagnostic = (array) ($diagnostics[$previousArtifact] ?? []);
                $previousPersisted = (array) data_get($persistedEvidenceState, $previousArtifact, []);
                $previousArtifactsGreen = $previousArtifactsGreen
                    && ((bool) data_get($previousDiagnostic, 'ready', false) || (bool) data_get($previousPersisted, 'persisted_green', false));
                $previousArtifactsPersisted = $previousArtifactsPersisted
                    && (bool) data_get($previousPersisted, 'persisted_green', false);
            }

            $supplied = (bool) data_get($diagnostic, 'supplied', false);
            $ready = (bool) data_get($diagnostic, 'ready', false);
            $persistedGreen = (bool) data_get($persisted, 'persisted_green', false);
            $outOfOrder = $supplied && ! $previousArtifactsGreen;

            if ($outOfOrder) {
                $violations[] = $artifact.'_supplied_before_previous_artifacts_green';
            }

            $artifactStates[] = [
                'order' => $index + 1,
                'artifact' => $artifact,
                'is_current_required_artifact' => $artifact === $nextRequired,
                'supplied_for_review' => $supplied,
                'diagnostic_ready' => $ready,
                'persisted_green' => $persistedGreen,
                'previous_artifacts' => $previousArtifacts,
                'previous_artifacts_green' => $previousArtifactsGreen,
                'previous_artifacts_persisted_green' => $previousArtifactsPersisted,
                'out_of_order_submission_detected' => $outOfOrder,
                'future_step_blocked_until_prior_green' => $index > $currentStepIndex && ! $previousArtifactsGreen,
            ];
        }

        $canonicalSteps = (array) data_get($canonicalSubmissionPersistencePlan, 'steps', []);
        $persistenceViolations = array_values(array_filter(array_map(
            static function (array $step): string {
                $status = (string) ($step['status'] ?? '');
                $blocker = (string) ($step['blocker'] ?? '');
                if (str_starts_with($status, 'ready') && $blocker !== '') {
                    return 'canonical_persistence_step_ready_with_blocker:'.(string) ($step['id'] ?? '');
                }

                return '';
            },
            $canonicalSteps,
        )));
        $violations = array_values(array_unique(array_merge($violations, $persistenceViolations)));

        $integrity = [
            'schema_version' => 'atlas.self_construction.operator_evidence_sequence_integrity.v1',
            'mode' => 'read_only_operator_evidence_sequence_integrity',
            'status' => $violations === [] ? 'sequence_integrity_ok' : 'sequence_integrity_blocked',
            'artifact_order' => $artifactOrder,
            'current_required_artifact' => $nextRequired,
            'current_step_index' => $currentStepIndex,
            'sequence_valid' => $violations === [],
            'sequence_violation_count' => count($violations),
            'sequence_violations' => $violations,
            'artifact_states' => $artifactStates,
            'canonical_persistence_plan_sequence_ordered' => (bool) data_get($canonicalSubmissionPersistencePlan, 'sequence_ordered', false),
            'canonical_persistence_next_step_id' => (string) data_get($canonicalSubmissionPersistencePlan, 'next_step_id', ''),
            'parallel_submission_allowed' => false,
            'can_skip_steps' => false,
            'can_persist_from_sequence_integrity' => false,
            'requires_fresh_readiness_after_each_persist' => true,
            'requires_fresh_completion_audit_after_each_persist' => true,
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'operator_evidence_sequence_integrity_does_not_persist_receipts',
                'operator_evidence_sequence_integrity_does_not_sign_for_operator',
                'operator_evidence_sequence_integrity_does_not_call_provider',
                'operator_evidence_sequence_integrity_does_not_spend_tokens',
                'operator_evidence_sequence_integrity_does_not_dispatch',
                'operator_evidence_sequence_integrity_does_not_enable_runtime',
                'operator_evidence_sequence_integrity_does_not_promote_completion',
            ],
        ];
        $integrity['sequence_integrity_hash'] = $this->stableHash($integrity);

        return $integrity;
    }

    /** @return array<string, mixed> */
    private function draftHashFinalizationNotRequested(): array
    {
        return $this->draftHashFinalizationSummarizer()->notRequested();
    }

    /** @return array<string, mixed> */
    private function draftHashFinalizationSummary(array $finalization, string $operatorDraftWorkspacePath): array
    {
        return $this->draftHashFinalizationSummarizer()->summary($finalization, $operatorDraftWorkspacePath);
    }

    private function draftHashFinalizationRequired(array $finalization): bool
    {
        return $this->draftHashFinalizationSummarizer()->required($finalization);
    }

    private function draftHashFinalizationSummarizer(): OperatorEvidenceDraftHashFinalizationSummarizer
    {
        return $this->draftHashFinalizationSummarizerInstance ??= new OperatorEvidenceDraftHashFinalizationSummarizer;
    }

    /**
     * @param  array<string, mixed>  $workspaceInput
     * @param  list<string>  $staleContextHashes
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @return array<string, mixed>
     */
    private function draftWorkspaceRefreshDecision(array $workspaceInput, array $staleContextHashes, array $runtimeGapMatrix): array
    {
        $workspaceLoaded = (string) data_get($workspaceInput, 'status') === 'loaded_for_read_only_submission_readiness';
        $reasons = [];

        if ($workspaceLoaded && $staleContextHashes !== []) {
            $reasons[] = 'runtime_promotion_receipt_context_hashes_are_stale';
        }
        if ($workspaceLoaded && (int) data_get($workspaceInput, 'warning_count', 0) > 0) {
            $reasons[] = 'draft_workspace_inspector_reported_warnings';
        }
        if ($workspaceLoaded && (int) data_get($workspaceInput, 'violation_count', 0) > 0) {
            $reasons[] = 'draft_workspace_inspector_reported_violations';
        }

        $required = $reasons !== [];

        return [
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace_refresh_decision.v1',
            'status' => $required ? 'refresh_recommended_before_operator_signature' : ($workspaceLoaded ? 'current_enough_for_readiness_review' : 'not_applicable'),
            'required' => $required,
            'reasons' => array_values(array_unique($reasons)),
            'stale_context_hashes' => $staleContextHashes,
            'current_hashes_for_new_workspace' => [
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            ],
            'refresh_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --persist-operator-draft-workspace --json',
            'post_refresh_review_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --operator-draft-workspace-path=<new_workspace_path> --json',
            'can_refresh_from_readiness' => false,
            'can_persist_evidence_from_refresh' => false,
            'non_execution_guarantees' => [
                'refresh_decision_does_not_write_workspace',
                'refresh_decision_does_not_persist_evidence',
                'refresh_decision_does_not_sign_for_operator',
                'refresh_decision_does_not_call_provider',
                'refresh_decision_does_not_spend_tokens',
                'refresh_decision_does_not_dispatch',
                'refresh_decision_does_not_promote_completion',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $explicit
     * @param  array<string, mixed>  $workspacePayloads
     * @param  array<string, mixed>  $canonicalSubmissionPayloads
     * @return array<string, mixed>
     */
    private function payloadOrWorkspaceOrCanonicalSubmission(array $explicit, array $workspacePayloads, array $canonicalSubmissionPayloads, string $workspaceKey): array
    {
        if ($explicit !== []) {
            return $explicit;
        }
        $workspacePayload = (array) data_get($workspacePayloads, $workspaceKey, []);
        if ($workspacePayload !== []) {
            return $workspacePayload;
        }

        return (array) data_get($canonicalSubmissionPayloads, $workspaceKey, []);
    }

    /** @return array<string, mixed> */
    private function loadCanonicalSubmissionInput(): array
    {
        return $this->submissionInputLoader()->loadCanonicalSubmissionInput();
    }

    /** @return array<string, mixed> */
    private function loadDraftWorkspaceInput(string $requestedPath): array
    {
        return $this->submissionInputLoader()->loadDraftWorkspaceInput($requestedPath);
    }

    private function submissionInputLoader(): OperatorEvidenceSubmissionInputLoader
    {
        return $this->submissionInputLoaderInstance ??= new OperatorEvidenceSubmissionInputLoader;
    }

    /**
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @param  array<string, mixed>  $completionReceipt
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @param  array<string, mixed>  $hashComposition
     * @param  array<string, string>  $humanContext
     * @return array<string, mixed>
     */
    private function operatorSubmissionEnvelopes(
        array $runtimeReceipt,
        array $realProviderSmoke,
        array $completionReceipt,
        array $diagnostics,
        array $hashComposition,
        array $humanContext,
        string $nextRequired,
    ): array {
        $runtimeEnvelope = $this->operatorSubmissionEnvelope(
            artifact: 'runtime_promotion_receipt',
            schemaVersion: 'atlas.self_construction.runtime_promotion_operator_submission_envelope.v1',
            sourceOptionKey: 'runtime_promotion_receipt',
            payload: $runtimeReceipt,
            payloadHash: (string) data_get($hashComposition, 'runtime_promotion_receipt.computed_hash', ''),
            hashField: 'receipt_hash',
            status: $this->envelopeStatus('runtime_promotion_receipt', (array) $diagnostics['runtime_promotion_receipt']),
            diagnostic: (array) $diagnostics['runtime_promotion_receipt'],
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json',
            postPersistenceCommands: [
                'runtime_gap_matrix' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
        );
        $smokeEnvelope = $this->operatorSubmissionEnvelope(
            artifact: 'real_provider_smoke',
            schemaVersion: 'atlas.self_construction.real_provider_smoke_operator_submission_envelope.v1',
            sourceOptionKey: 'real_provider_smoke',
            payload: $realProviderSmoke,
            payloadHash: (string) data_get($hashComposition, 'real_provider_smoke.computed_hash', ''),
            hashField: 'smoke_hash',
            status: $this->envelopeStatus('real_provider_smoke', (array) $diagnostics['real_provider_smoke']),
            diagnostic: (array) $diagnostics['real_provider_smoke'],
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-endgame-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
            postPersistenceCommands: [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'human_completion_receipt_closure_pack' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --json',
            ],
        );
        $humanEnvelope = $this->operatorSubmissionEnvelope(
            artifact: 'human_completion_receipt',
            schemaVersion: 'atlas.self_construction.human_completion_receipt_operator_submission_envelope.v1',
            sourceOptionKey: 'completion_receipt',
            payload: $completionReceipt,
            payloadHash: (string) data_get($hashComposition, 'human_completion_receipt.computed_hash', ''),
            hashField: 'receipt_hash',
            status: $this->envelopeStatus('human_completion_receipt', (array) $diagnostics['human_completion_receipt']),
            diagnostic: (array) $diagnostics['human_completion_receipt'],
            persistCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            detailedEndgameCommand: 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            postPersistenceCommands: [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'finalization_gate' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-finalization-gate-status --json',
            ],
            context: $humanContext,
        );

        $envelopes = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_readiness_envelopes.v1',
            'mode' => 'read_only_operator_submission_envelope_readiness',
            'status' => $nextRequired === 'rerun_completion_audit'
                ? 'all_operator_submission_envelopes_ready'
                : 'blocked_until_required_operator_submission_envelopes_are_ready',
            'next_required_envelope' => $nextRequired === 'rerun_completion_audit' ? '' : $nextRequired,
            'required_envelope_count' => 3,
            'ready_envelope_count' => count(array_filter([
                $runtimeEnvelope,
                $smokeEnvelope,
                $humanEnvelope,
            ], fn (array $envelope): bool => (bool) $envelope['ready_for_explicit_operator_persistence'])),
            'source_option_keys' => [
                'runtime_promotion_receipt',
                'real_provider_smoke',
                'completion_receipt',
            ],
            'runtime_promotion_receipt' => $runtimeEnvelope,
            'real_provider_smoke' => $smokeEnvelope,
            'human_completion_receipt' => $humanEnvelope,
            'can_persist_from_readiness' => false,
            'non_execution_guarantees' => [
                'readiness_envelopes_do_not_persist_receipts',
                'readiness_envelopes_do_not_persist_smoke',
                'readiness_envelopes_do_not_sign_for_operator',
                'readiness_envelopes_do_not_call_provider',
                'readiness_envelopes_do_not_spend_tokens',
                'readiness_envelopes_do_not_dispatch',
                'readiness_envelopes_do_not_promote_completion',
            ],
        ];
        $envelopes['operator_submission_envelopes_hash'] = $this->stableHash($envelopes);

        return $envelopes;
    }

    /**
     * @param  array<string, mixed>  $canonicalSubmissionInput
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @return array<string, mixed>
     */
    private function canonicalSubmissionPersistencePlan(array $canonicalSubmissionInput, array $diagnostics, bool $explicitPayloadSupplied, bool $workspacePayloadSupplied, array $persistedEvidenceState): array
    {
        $loadedArtifacts = (array) data_get($canonicalSubmissionInput, 'loaded_artifacts', []);
        $canonicalSourceAuthoritative = ! $explicitPayloadSupplied && ! $workspacePayloadSupplied;

        $runtimePersisted = (bool) data_get($persistedEvidenceState, 'runtime_promotion_receipt.persisted_green', false);
        $smokePersisted = (bool) data_get($persistedEvidenceState, 'real_provider_smoke.persisted_green', false);
        $humanPersisted = (bool) data_get($persistedEvidenceState, 'human_completion_receipt.persisted_green', false);
        $runtimeFileReady = $canonicalSourceAuthoritative
            && in_array('runtime_promotion_receipt', $loadedArtifacts, true)
            && (bool) data_get($diagnostics, 'runtime_promotion_receipt.ready', false);
        $smokeFileReady = $canonicalSourceAuthoritative
            && in_array('real_provider_smoke', $loadedArtifacts, true)
            && (bool) data_get($diagnostics, 'real_provider_smoke.ready', false);
        $humanFileReady = $canonicalSourceAuthoritative
            && in_array('human_completion_receipt', $loadedArtifacts, true)
            && (bool) data_get($diagnostics, 'human_completion_receipt.ready', false);

        $steps = [
            $this->canonicalSubmissionPersistenceStep(
                order: 1,
                id: 'persist_runtime_promotion_receipt',
                artifact: 'runtime_promotion_receipt',
                loadedArtifacts: $loadedArtifacts,
                diagnostic: (array) $diagnostics['runtime_promotion_receipt'],
                prerequisiteReady: $canonicalSourceAuthoritative,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@'.self::CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS['runtime_promotion_receipt'].' --persist-runtime-promotion-receipt --json',
                prerequisiteBlocker: $canonicalSourceAuthoritative ? '' : 'canonical_submission_files_not_authoritative_for_current_readiness_input',
                persistedEvidenceAlreadyGreen: $runtimePersisted,
            ),
            $this->canonicalSubmissionPersistenceStep(
                order: 2,
                id: 'persist_real_provider_smoke',
                artifact: 'real_provider_smoke',
                loadedArtifacts: $loadedArtifacts,
                diagnostic: (array) $diagnostics['real_provider_smoke'],
                prerequisiteReady: $runtimePersisted,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@'.self::CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS['real_provider_smoke'].' --persist-completion-evidence --json',
                prerequisiteBlocker: $runtimePersisted ? '' : 'runtime_promotion_receipt_must_be_persisted_first',
                persistedEvidenceAlreadyGreen: $smokePersisted,
            ),
            $this->canonicalSubmissionPersistenceStep(
                order: 3,
                id: 'persist_human_completion_receipt',
                artifact: 'human_completion_receipt',
                loadedArtifacts: $loadedArtifacts,
                diagnostic: (array) $diagnostics['human_completion_receipt'],
                prerequisiteReady: $runtimePersisted && $smokePersisted,
                command: 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@'.self::CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS['human_completion_receipt'].' --persist-completion-evidence --json',
                prerequisiteBlocker: ($runtimePersisted && $smokePersisted) ? '' : 'runtime_promotion_and_real_provider_smoke_must_be_persisted_first',
                persistedEvidenceAlreadyGreen: $humanPersisted,
            ),
            [
                'order' => 4,
                'id' => 'rerun_completion_audit',
                'artifact' => 'atlas_self_construction_os_completion_audit',
                'canonical_submission_path' => '',
                'status' => $runtimePersisted && $smokePersisted && $humanPersisted
                    ? 'ready_after_persisted_evidence_steps'
                    : 'blocked_until_all_canonical_submission_persistence_steps_are_ready',
                'command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'requires_explicit_persistence_flag' => false,
                'can_run_from_readiness' => false,
                'persisted_evidence_already_green' => false,
                'blocker' => $runtimePersisted && $smokePersisted && $humanPersisted ? '' : 'canonical_submission_persistence_not_ready',
            ],
        ];

        $nextStep = '';
        foreach ($steps as $step) {
            if (! (bool) ($step['persisted_evidence_already_green'] ?? false)) {
                $nextStep = (string) $step['id'];
                break;
            }
        }

        $allPersisted = $runtimePersisted && $smokePersisted && $humanPersisted;
        $anyFileReady = $runtimeFileReady || $smokeFileReady || $humanFileReady;

        $payload = [
            'schema_version' => 'atlas.self_construction.canonical_submission_persistence_plan.v1',
            'mode' => 'read_only_canonical_submission_persistence_plan',
            'status' => $allPersisted
                ? 'all_evidence_already_persisted_rerun_completion_audit'
                : ($anyFileReady ? 'ready_for_next_explicit_operator_persistence_step' : ($loadedArtifacts === [] ? 'no_canonical_submission_files_loaded' : 'blocked_until_canonical_submission_files_are_ready')),
            'canonical_submission_directory' => 'storage/app/atlas/self-construction/operator-submissions',
            'canonical_submission_private_storage_directory' => 'storage/app/private/atlas/self-construction/operator-submissions',
            'canonical_source_authoritative' => $canonicalSourceAuthoritative,
            'explicit_payload_supplied' => $explicitPayloadSupplied,
            'workspace_payload_supplied' => $workspacePayloadSupplied,
            'persisted_evidence_state' => $persistedEvidenceState,
            'loaded_artifacts' => $loadedArtifacts,
            'loaded_artifact_count' => count($loadedArtifacts),
            'required_artifact_count' => 3,
            'sequence_ordered' => true,
            'requires_explicit_operator_persistence_commands' => true,
            'human_receipt_persistence_requires_runtime_and_smoke_green' => true,
            'human_receipt_persistence_requires_prior_persisted_smoke_command' => true,
            'next_step_id' => $nextStep,
            'steps' => $steps,
            'can_persist_from_readiness' => false,
            'can_promote_completion_from_plan' => false,
            'non_execution_guarantees' => [
                'canonical_submission_persistence_plan_does_not_persist_receipts',
                'canonical_submission_persistence_plan_does_not_persist_smoke',
                'canonical_submission_persistence_plan_does_not_sign_for_operator',
                'canonical_submission_persistence_plan_does_not_call_provider',
                'canonical_submission_persistence_plan_does_not_spend_tokens',
                'canonical_submission_persistence_plan_does_not_dispatch',
                'canonical_submission_persistence_plan_does_not_enable_runtime',
                'canonical_submission_persistence_plan_does_not_promote_completion',
            ],
        ];
        $payload['canonical_submission_persistence_plan_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @param array<string, mixed> $runtimeGapMatrix */
    private function persistedEvidenceState(array $runtimeGapMatrix): array
    {
        $runtimeReceipt = (array) data_get($runtimeGapMatrix, 'runtime_promotion_receipt', []);
        $realProviderSmoke = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify();
        $humanCompletionReceipt = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify();

        return [
            'runtime_promotion_receipt' => [
                'status' => (string) data_get($runtimeReceipt, 'status', ''),
                'receipt_hash' => (string) data_get($runtimeReceipt, 'receipt_hash', ''),
                'persisted_green' => (string) data_get($runtimeReceipt, 'status') === 'passed'
                    && (bool) data_get($runtimeGapMatrix, 'all_runtime_y', false),
            ],
            'real_provider_smoke' => [
                'status' => (string) data_get($realProviderSmoke, 'status', ''),
                'smoke_hash' => (string) data_get($realProviderSmoke, 'smoke_hash', ''),
                'persisted_green' => (string) data_get($realProviderSmoke, 'status') === 'passed',
            ],
            'human_completion_receipt' => [
                'status' => (string) data_get($humanCompletionReceipt, 'status', ''),
                'receipt_hash' => (string) data_get($humanCompletionReceipt, 'receipt_hash', ''),
                'persisted_green' => (string) data_get($humanCompletionReceipt, 'status') === 'passed',
            ],
        ];
    }

    /**
     * @param  list<string>  $loadedArtifacts
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function canonicalSubmissionPersistenceStep(int $order, string $id, string $artifact, array $loadedArtifacts, array $diagnostic, bool $prerequisiteReady, string $command, string $prerequisiteBlocker, bool $persistedEvidenceAlreadyGreen): array
    {
        $loaded = in_array($artifact, $loadedArtifacts, true);
        $ready = $loaded && $prerequisiteReady && (bool) data_get($diagnostic, 'ready', false);
        $violationCodes = (array) data_get($diagnostic, 'violation_codes', []);
        $staleContextHashes = $this->canonicalSubmissionStaleContextHashes($violationCodes);
        $path = match ($artifact) {
            'runtime_promotion_receipt' => 'storage/app/atlas/self-construction/operator-submissions/runtime-promotion.json',
            'real_provider_smoke' => 'storage/app/atlas/self-construction/operator-submissions/real-provider-smoke.json',
            default => 'storage/app/atlas/self-construction/operator-submissions/completion-receipt.json',
        };
        $privatePath = self::CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS[$artifact] ?? self::CANONICAL_SUBMISSION_PRIVATE_STORAGE_PATHS['human_completion_receipt'];

        $blocker = match (true) {
            ! $loaded => 'canonical_submission_file_missing',
            ! $prerequisiteReady => $prerequisiteBlocker,
            ! (bool) data_get($diagnostic, 'ready', false) => 'canonical_submission_verifier_not_ready',
            default => '',
        };

        return [
            'order' => $order,
            'id' => $id,
            'artifact' => $artifact,
            'canonical_submission_path' => $path,
            'canonical_submission_private_storage_path' => $privatePath,
            'status' => $persistedEvidenceAlreadyGreen ? 'already_persisted_evidence_green' : ($ready ? 'ready_for_explicit_operator_persistence' : 'blocked_until_canonical_submission_verifier_passes'),
            'verifier_status' => (string) data_get($diagnostic, 'status', 'not_supplied'),
            'ready_for_explicit_operator_persistence' => $ready,
            'persisted_evidence_already_green' => $persistedEvidenceAlreadyGreen,
            'command' => $command,
            'requires_explicit_persistence_flag' => true,
            'can_run_from_readiness' => false,
            'blocker' => $blocker,
            'errors' => (array) data_get($diagnostic, 'errors', []),
            'placeholder_fields' => (array) data_get($diagnostic, 'placeholders', []),
            'stale_context_hashes' => $staleContextHashes,
            'stale_context_hash_count' => count($staleContextHashes),
            'fresh_operator_draft_required' => $artifact === 'runtime_promotion_receipt'
                && ($staleContextHashes !== [] || (array) data_get($diagnostic, 'placeholders', []) !== []),
            'violation_count' => (int) data_get($diagnostic, 'violation_count', 0),
            'violation_codes' => $violationCodes,
            'violations' => (array) data_get($diagnostic, 'violations', []),
        ];
    }

    /** @param list<string> $violationCodes */
    private function canonicalSubmissionStaleContextHashes(array $violationCodes): array
    {
        $stale = [];
        $codeToField = [
            'runtime_gap_matrix_hash_mismatch' => 'runtime_gap_matrix_hash',
            'runtime_promotion_basis_hash_mismatch' => 'runtime_promotion_basis_hash',
            'runtime_promotion_closure_basis_hash_mismatch' => 'runtime_promotion_closure_basis_hash',
            'graduation_hash_mismatch' => 'graduation_evidence_hashes',
        ];
        foreach ($codeToField as $code => $field) {
            if (in_array($code, $violationCodes, true)) {
                $stale[] = $field;
            }
        }

        return $stale;
    }

    /**
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @param  array<string, mixed>  $operatorSubmissionEnvelopes
     * @param  array<string, mixed>  $canonicalSubmissionPersistencePlan
     * @param  array<string, mixed>  $persistedEvidenceState
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $operatorNextAction
     * @return array<string, mixed>
     */
    private function operatorCompletionProofBundle(
        array $diagnostics,
        array $operatorSubmissionEnvelopes,
        array $canonicalSubmissionPersistencePlan,
        array $persistedEvidenceState,
        array $completionAudit,
        array $operatorNextAction,
    ): array {
        $artifactOrder = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ];
        $proofs = [];
        foreach ($artifactOrder as $artifact) {
            $envelope = (array) data_get($operatorSubmissionEnvelopes, $artifact, []);
            $persisted = (array) data_get($persistedEvidenceState, $artifact, []);
            $diagnostic = (array) ($diagnostics[$artifact] ?? []);
            $proofs[] = [
                'artifact' => $artifact,
                'diagnostic_status' => (string) data_get($diagnostic, 'status', ''),
                'diagnostic_ready' => (bool) data_get($diagnostic, 'ready', false),
                'envelope_status' => (string) data_get($envelope, 'status', ''),
                'ready_for_explicit_operator_persistence' => (bool) data_get($envelope, 'ready_for_explicit_operator_persistence', false),
                'persisted_green' => (bool) data_get($persisted, 'persisted_green', false),
                'evidence_hash' => (string) data_get($persisted, 'receipt_hash', (string) data_get($persisted, 'smoke_hash', '')),
                'payload_hash' => (string) data_get($envelope, 'payload_hash', ''),
                'payload_declared_hash' => (string) data_get($envelope, 'payload_declared_hash', ''),
                'operator_submission_envelope_hash' => (string) data_get($envelope, 'operator_submission_envelope_hash', ''),
                'persist_command' => (string) data_get($envelope, 'persist_command', ''),
                'post_persistence_rerun_commands' => (array) data_get($envelope, 'post_persistence_rerun_commands', []),
                'errors' => (array) data_get($diagnostic, 'errors', []),
            ];
        }

        $missingProofs = array_values(array_map(
            static fn (array $proof): string => (string) $proof['artifact'],
            array_filter($proofs, static fn (array $proof): bool => ! (bool) $proof['persisted_green']),
        ));
        $readyForFinalAudit = $missingProofs === [];

        $bundle = [
            'schema_version' => 'atlas.self_construction.operator_completion_proof_bundle.v1',
            'mode' => 'read_only_operator_completion_proof_bundle',
            'status' => $readyForFinalAudit ? 'ready_for_final_completion_audit' : 'blocked_missing_operator_proofs',
            'artifact_order' => $artifactOrder,
            'proofs' => $proofs,
            'missing_proofs' => $missingProofs,
            'missing_proof_count' => count($missingProofs),
            'ready_for_final_completion_audit' => $readyForFinalAudit,
            'completion_audit_status' => (string) data_get($completionAudit, 'status', ''),
            'completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'canonical_submission_persistence_plan_hash' => (string) data_get($canonicalSubmissionPersistencePlan, 'canonical_submission_persistence_plan_hash', ''),
            'operator_submission_envelopes_hash' => (string) data_get($operatorSubmissionEnvelopes, 'operator_submission_envelopes_hash', ''),
            'operator_next_action_hash' => (string) data_get($operatorNextAction, 'operator_next_action_hash', ''),
            'proof_commands' => [
                'submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'persist_terminal_loop_operational_proof_binding' => $this->terminalLoopOperationalProofBindingPersistCommand(),
                'completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'effective_completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'completion_audit' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'effective_completion_audit' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'completion_audit_diagnostic' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'finalization_gate' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-finalization-gate-status --json',
            ],
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofBindingArtifactPath(),
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'terminal_loop_operational_proof_required_end_to_end_contract_capabilities' => self::REQUIRED_TERMINAL_LOOP_END_TO_END_CONTRACT_CAPABILITIES,
            'terminal_loop_operational_proof_acceptance_criteria' => [
                'operational_proof_status_passed',
                'operational_readiness_matrix_all_true',
                'post_cycle_cycle_supervisor_review_evidence',
                'post_cycle_end_to_end_contract_available',
                'post_cycle_end_to_end_contract_covers_required_loop_surfaces',
                'completion_audit_binding_packet_ready',
                'provider_token_dispatch_flags_false',
            ],
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'can_persist_from_proof_bundle' => false,
            'can_promote_completion_from_proof_bundle' => false,
            'non_execution_guarantees' => [
                'operator_completion_proof_bundle_does_not_persist_receipts',
                'operator_completion_proof_bundle_does_not_sign_for_operator',
                'operator_completion_proof_bundle_does_not_call_provider',
                'operator_completion_proof_bundle_does_not_spend_tokens',
                'operator_completion_proof_bundle_does_not_dispatch',
                'operator_completion_proof_bundle_does_not_enable_runtime',
                'operator_completion_proof_bundle_does_not_promote_completion',
            ],
        ];
        $bundle['operator_completion_proof_bundle_hash'] = $this->stableHash($bundle);

        return $bundle;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProofBundle
     * @param  array<string, mixed>  $canonicalSubmissionPersistencePlan
     * @return array<string, mixed>
     */
    private function operatorEvidenceClosureRunbook(array $completionAudit, array $operatorNextAction, array $operatorCompletionProofBundle, array $canonicalSubmissionPersistencePlan): array
    {
        $terminalLoopHealth = (new AgentControlPlaneTerminalLoopHealthDigestService)->digest([
            'actor' => 'operator-evidence-closure-runbook',
            'target_min_claimable_tasks' => 6,
            'max_new_tasks' => 0,
        ]);
        $terminalLoopReady = (string) ($terminalLoopHealth['status'] ?? '') === 'ready'
            && (bool) data_get($terminalLoopHealth, 'loop_decision.safe_to_start_new_worker', false)
            && (int) data_get($terminalLoopHealth, 'queue_health.claimed_task_count', 0) === 0
            && (int) data_get($terminalLoopHealth, 'lease_health.active_lease_count', 0) === 0
            && (int) data_get($terminalLoopHealth, 'lease_health.recoverable_lease_count', 0) === 0;
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);
        $nextActionCommand = (string) data_get($operatorNextAction, 'exact_command', '');
        $nextPersistCommand = (string) data_get($operatorNextAction, 'exact_persist_command', '');
        $currentOperatorStepId = (string) data_get($operatorNextAction, 'action_step_id', '');
        if (! $terminalLoopReady) {
            $currentOperatorStepId = 'verify_terminal_loop_health';
        }

        $steps = [
            [
                'order' => 1,
                'id' => 'verify_terminal_loop_health',
                'phase' => 'loop_preflight',
                'status' => $terminalLoopReady ? 'passed' : 'blocked_terminal_loop_health_not_ready',
                'command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-health-digest-status --json',
                'done' => $terminalLoopReady,
                'requires_operator_judgment' => false,
                'can_run_from_runbook' => false,
            ],
            [
                'order' => 2,
                'id' => 'review_operator_next_action',
                'phase' => 'operator_review',
                'status' => $terminalLoopReady ? (string) data_get($operatorNextAction, 'status', '') : 'blocked_until_terminal_loop_health_ready',
                'command' => $nextActionCommand,
                'persist_command' => $nextPersistCommand,
                'done' => data_get($operatorNextAction, 'next_required') === 'rerun_completion_audit',
                'requires_operator_judgment' => true,
                'can_run_from_runbook' => false,
            ],
            [
                'order' => 3,
                'id' => 'follow_canonical_persistence_plan',
                'phase' => 'explicit_persistence',
                'status' => (string) data_get($canonicalSubmissionPersistencePlan, 'status', ''),
                'command' => $nextPersistCommand,
                'done' => data_get($operatorCompletionProofBundle, 'missing_proof_count') === 0,
                'requires_operator_judgment' => true,
                'can_run_from_runbook' => false,
            ],
            [
                'order' => 4,
                'id' => 'refresh_terminal_loop_operational_proof',
                'phase' => 'terminal_loop_operational_proof',
                'status' => data_get($operatorCompletionProofBundle, 'ready_for_final_completion_audit') ? 'required_before_final_completion_audit' : 'blocked_until_all_operator_proofs_persisted',
                'command' => $this->terminalLoopOperationalProofCommand(),
                'audit_command_with_binding' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'effective_audit_command_with_binding' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
                'done' => false,
                'requires_operator_judgment' => false,
                'can_run_from_runbook' => false,
            ],
            [
                'order' => 5,
                'id' => 'rerun_completion_audit',
                'phase' => 'final_verification',
                'status' => data_get($operatorCompletionProofBundle, 'ready_for_final_completion_audit') ? 'ready_after_all_operator_proofs' : 'blocked_until_all_operator_proofs_persisted',
                'command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                'effective_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'fallback_command_without_binding' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'done' => (string) data_get($completionAudit, 'status') === 'complete',
                'requires_operator_judgment' => false,
                'can_run_from_runbook' => false,
            ],
        ];

        $runbook = [
            'schema_version' => 'atlas.self_construction.operator_evidence_closure_runbook.v1',
            'mode' => 'read_only_operator_evidence_closure_runbook',
            'status' => $terminalLoopReady ? 'operator_action_required' : 'blocked_terminal_loop_not_ready',
            'current_operator_step_id' => $currentOperatorStepId,
            'completion_audit_status' => (string) data_get($completionAudit, 'status', ''),
            'completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'failed_criteria' => $failedCriteria,
            'failed_criteria_count' => count($failedCriteria),
            'blocker_classification' => (array) data_get($completionAudit, 'blocker_classification', []),
            'terminal_loop_preflight' => [
                'status' => (string) ($terminalLoopHealth['status'] ?? ''),
                'ready' => $terminalLoopReady,
                'claimable_task_count' => (int) data_get($terminalLoopHealth, 'queue_health.claimable_task_count', 0),
                'claimed_task_count' => (int) data_get($terminalLoopHealth, 'queue_health.claimed_task_count', 0),
                'active_lease_count' => (int) data_get($terminalLoopHealth, 'lease_health.active_lease_count', 0),
                'recoverable_lease_count' => (int) data_get($terminalLoopHealth, 'lease_health.recoverable_lease_count', 0),
                'safe_to_start_new_worker' => (bool) data_get($terminalLoopHealth, 'loop_decision.safe_to_start_new_worker', false),
                'fleet_launch_plan_status' => (string) data_get($terminalLoopHealth, 'terminal_loop_fleet_launch_plan.status', ''),
                'terminal_loop_health_digest_hash' => (string) data_get($terminalLoopHealth, 'terminal_loop_health_digest_hash', ''),
            ],
            'ordered_steps' => $steps,
            'ordered_step_count' => count($steps),
            'missing_operator_proofs' => (array) data_get($operatorCompletionProofBundle, 'missing_proofs', []),
            'missing_operator_proof_count' => (int) data_get($operatorCompletionProofBundle, 'missing_proof_count', 0),
            'operator_next_action_hash' => (string) data_get($operatorNextAction, 'operator_next_action_hash', ''),
            'operator_completion_proof_bundle_hash' => (string) data_get($operatorCompletionProofBundle, 'operator_completion_proof_bundle_hash', ''),
            'canonical_submission_persistence_plan_hash' => (string) data_get($canonicalSubmissionPersistencePlan, 'canonical_submission_persistence_plan_hash', ''),
            'terminal_loop_operational_proof_command' => $this->terminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_binding_persist_command' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'completion_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            'completion_audit_with_canonical_terminal_loop_operational_proof_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'effective_completion_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_canonical_binding_path' => $this->terminalLoopOperationalProofBindingArtifactPath(),
            'terminal_loop_operational_proof_required_before_final_audit' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'can_execute_from_runbook' => false,
            'can_persist_from_runbook' => false,
            'can_sign_from_runbook' => false,
            'can_call_provider_from_runbook' => false,
            'non_execution_guarantees' => [
                'operator_evidence_closure_runbook_does_not_claim_tasks',
                'operator_evidence_closure_runbook_does_not_recover_leases',
                'operator_evidence_closure_runbook_does_not_persist_receipts',
                'operator_evidence_closure_runbook_does_not_sign_for_operator',
                'operator_evidence_closure_runbook_does_not_call_provider',
                'operator_evidence_closure_runbook_does_not_spend_tokens',
                'operator_evidence_closure_runbook_does_not_dispatch',
                'operator_evidence_closure_runbook_does_not_enable_runtime',
                'operator_evidence_closure_runbook_does_not_promote_completion',
            ],
        ];
        $runbook['operator_evidence_closure_runbook_hash'] = $this->stableHash($runbook);

        return $runbook;
    }

    /**
     * @param  array<string, mixed>  $operatorSubmissionEnvelopes
     * @param  array<string, mixed>  $canonicalSubmissionPersistencePlan
     * @return array<string, mixed>
     */
    private function operatorNextAction(string $nextRequired, string $nextRequiredCommand, array $operatorSubmissionEnvelopes, array $canonicalSubmissionPersistencePlan): array
    {
        $artifactKey = match ($nextRequired) {
            'runtime_promotion_receipt' => 'runtime_promotion_receipt',
            'real_provider_smoke' => 'real_provider_smoke',
            'human_completion_receipt' => 'human_completion_receipt',
            default => '',
        };
        $envelope = $artifactKey === '' ? [] : (array) data_get($operatorSubmissionEnvelopes, $artifactKey, []);
        $canonicalNextStepId = (string) data_get($canonicalSubmissionPersistencePlan, 'next_step_id', '');
        $canonicalNextStep = collect((array) data_get($canonicalSubmissionPersistencePlan, 'steps', []))
            ->firstWhere('id', $canonicalNextStepId) ?? [];
        $canonicalNextStepArtifact = (string) data_get($canonicalNextStep, 'artifact', '');
        $canonicalStepReady = str_starts_with((string) data_get($canonicalNextStep, 'status', ''), 'ready');
        $readyForPersistence = (bool) data_get($envelope, 'ready_for_explicit_operator_persistence', false);

        $exactCommand = $nextRequired === 'rerun_completion_audit'
            ? $this->nextRequiredCommand('rerun_completion_audit')
            : ($readyForPersistence ? (string) data_get($envelope, 'detailed_endgame_command', $nextRequiredCommand) : $nextRequiredCommand);
        $exactPersistCommand = $readyForPersistence
            ? (string) data_get($envelope, 'persist_command', '')
            : '';
        $expectedPersistCommandTemplate = (string) data_get($envelope, 'persist_command', '');
        $expectedEndgameCommandTemplate = (string) data_get($envelope, 'detailed_endgame_command', '');
        if ($canonicalStepReady && (string) data_get($canonicalNextStep, 'command', '') !== '') {
            $exactCommand = (string) data_get($canonicalNextStep, 'command', $exactCommand);
            $exactPersistCommand = $exactCommand;
            $expectedPersistCommandTemplate = $exactCommand;
            $expectedEndgameCommandTemplate = '';
        }

        $shellPacket = $this->operatorNextActionShellPacket(
            nextRequired: $nextRequired,
            actionArtifact: $canonicalStepReady ? $canonicalNextStepArtifact : $artifactKey,
            exactCommand: $exactCommand,
            exactPersistCommand: $exactPersistCommand,
            expectedPersistCommandTemplate: $expectedPersistCommandTemplate,
            readyForPersistence: $canonicalStepReady || $readyForPersistence,
        );

        $payload = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_readiness_next_action.v1',
            'status' => $nextRequired === 'rerun_completion_audit' ? 'ready_for_completion_audit_rerun' : 'blocked_operator_action_required',
            'next_required' => $nextRequired,
            'next_artifact' => $artifactKey,
            'action_step_id' => $canonicalStepReady ? $canonicalNextStepId : $nextRequired,
            'action_artifact' => $canonicalStepReady ? $canonicalNextStepArtifact : $artifactKey,
            'action_source' => $canonicalStepReady ? 'canonical_submission_persistence_plan' : 'next_required_verifier',
            'envelope_status' => (string) data_get($envelope, 'status', ''),
            'canonical_submission_persistence_plan_status' => (string) data_get($canonicalSubmissionPersistencePlan, 'status', ''),
            'canonical_submission_persistence_plan_next_step_id' => $canonicalNextStepId,
            'exact_command' => $exactCommand,
            'exact_persist_command' => $exactPersistCommand,
            'expected_persist_command_template' => $expectedPersistCommandTemplate,
            'expected_endgame_command_template' => $expectedEndgameCommandTemplate,
            'persist_command_template_available' => $expectedPersistCommandTemplate !== '',
            'command_contains_placeholders' => $this->placeholderFieldsFromCommand($exactCommand.' '.$exactPersistCommand) !== [],
            'placeholder_fields_to_replace' => $this->placeholderFieldsFromCommand($exactCommand.' '.$exactPersistCommand),
            'ready_for_explicit_operator_persistence' => $canonicalStepReady || $readyForPersistence,
            'can_run_automatically' => false,
            'can_persist_from_readiness' => false,
            'why_not_automatic' => match ($nextRequired) {
                'runtime_promotion_receipt' => 'requires_operator_signature_and_runtime_promotion_judgment',
                'real_provider_smoke' => 'requires_operator_observed_real_provider_evidence',
                'human_completion_receipt' => 'requires_human_completion_judgment_and_signed_receipt',
                'rerun_completion_audit' => 'operator_must_confirm_all_evidence_persisted_before_treating_audit_as_final',
                default => 'requires_operator_review',
            },
            'current_errors' => (array) data_get($envelope, 'errors', []),
            'pre_persist_operator_checks' => (array) data_get($envelope, 'pre_persist_operator_checks', []),
            'proof_commands_after_action' => array_values(array_filter([
                (string) data_get($envelope, 'post_persistence_rerun_commands.completion_evidence_status', ''),
                $this->terminalLoopOperationalProofCommand(),
                $this->completionAuditWithTerminalLoopOperationalProofCommand(),
                (string) data_get($envelope, 'post_persistence_rerun_commands.completion_audit', ''),
                'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            ])),
            'shell_packet' => $shellPacket,
            'success_predicate_after_all_actions' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'submission_readiness_next_action_does_not_execute_command',
                'submission_readiness_next_action_does_not_persist_receipts',
                'submission_readiness_next_action_does_not_call_provider',
                'submission_readiness_next_action_does_not_spend_tokens',
                'submission_readiness_next_action_does_not_promote_completion',
            ],
        ];
        $payload['operator_next_action_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function operatorNextActionShellPacket(
        string $nextRequired,
        string $actionArtifact,
        string $exactCommand,
        string $exactPersistCommand,
        string $expectedPersistCommandTemplate,
        bool $readyForPersistence,
    ): array {
        $commandToCopy = $readyForPersistence && $exactPersistCommand !== ''
            ? $exactPersistCommand
            : $exactCommand;
        $placeholders = $this->placeholderFieldsFromCommand($commandToCopy);
        $packet = [
            'schema_version' => 'atlas.self_construction.operator_evidence_submission_readiness_next_action_shell_packet.v1',
            'mode' => 'read_only_operator_evidence_submission_readiness_next_action_shell_packet',
            'status' => $placeholders === [] ? 'copy_ready_after_fresh_readiness_review' : 'blocked_placeholder_replacement_required',
            'next_required' => $nextRequired,
            'action_artifact' => $actionArtifact,
            'command_to_copy' => $commandToCopy,
            'command_to_copy_hash' => $commandToCopy === '' ? '' : hash('sha256', $commandToCopy),
            'exact_command' => $exactCommand,
            'exact_command_hash' => $exactCommand === '' ? '' : hash('sha256', $exactCommand),
            'exact_persist_command' => $exactPersistCommand,
            'exact_persist_command_hash' => $exactPersistCommand === '' ? '' : hash('sha256', $exactPersistCommand),
            'expected_persist_command_template' => $expectedPersistCommandTemplate,
            'placeholder_count' => count($placeholders),
            'placeholders' => $placeholders,
            'copy_safe' => $placeholders === [] && $commandToCopy !== '',
            'ready_for_explicit_operator_persistence' => $readyForPersistence,
            'requires_fresh_readiness_status_before_copy' => true,
            'requires_verifier_green_before_persist' => true,
            'can_resume_without_chat_history' => true,
            'post_action_proof_commands' => [
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
                'completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand(),
            ],
            'success_check' => match ($nextRequired) {
                'runtime_promotion_receipt' => 'runtime_promotion_receipt.status=passed AND runtime_gap_matrix.all_runtime_y=true',
                'real_provider_smoke' => 'real_provider_smoke.status=passed AND real_provider_run_observed_by_operator=true',
                'human_completion_receipt' => 'human_completion_receipt.status=passed AND completion_claim_allowed=true',
                'rerun_completion_audit' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
                default => 'operator_evidence_submission_readiness.next_required_submission_advances',
            },
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_verifier_status_is_not_passed',
                'stop_if_hash_mismatch',
                'stop_if_persist_command_is_run_before_draft_is_green',
                'stop_if_real_provider_smoke_is_not_operator_observed',
            ],
            'non_execution_guarantees' => [
                'shell_packet_does_not_execute_command',
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

    private function terminalLoopOperationalProofCommand(): string
    {
        return OperatorEvidence\TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofCommand();
    }

    private function terminalLoopOperationalProofBindingPersistCommand(): string
    {
        return OperatorEvidence\TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingPersistCommand();
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(): string
    {
        return OperatorEvidence\TerminalLoopOperationalProofCommandFactory::completionAuditWithTerminalLoopOperationalProofCommand();
    }

    private function completionAuditWithCanonicalTerminalLoopOperationalProofCommand(): string
    {
        return OperatorEvidence\TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand();
    }

    private function terminalLoopOperationalProofBindingArtifactPath(): string
    {
        return OperatorEvidence\TerminalLoopOperationalProofCommandFactory::terminalLoopOperationalProofBindingArtifactPath();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $diagnostic
     * @param  array<string, string>  $postPersistenceCommands
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    private function operatorSubmissionEnvelope(
        string $artifact,
        string $schemaVersion,
        string $sourceOptionKey,
        array $payload,
        string $payloadHash,
        string $hashField,
        string $status,
        array $diagnostic,
        string $persistCommand,
        string $detailedEndgameCommand,
        array $postPersistenceCommands,
        array $context = [],
    ): array {
        $payloadJson = $payload === []
            ? ''
            : (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = [
            'schema_version' => $schemaVersion,
            'mode' => 'read_only_operator_submission_envelope_readiness',
            'artifact' => $artifact,
            'status' => $status,
            'source_option_key' => $sourceOptionKey,
            'payload_present' => $payload !== [],
            'payload_under_review' => $payload,
            'payload_json_sha256' => $payloadJson === '' ? '' : hash('sha256', $payloadJson),
            'payload_hash' => $payloadHash,
            'payload_declared_hash' => (string) ($payload[$hashField] ?? ''),
            'hash_field' => $hashField,
            'verifier_status' => (string) ($diagnostic['status'] ?? ''),
            'ready_for_explicit_operator_persistence' => (bool) ($diagnostic['ready'] ?? false),
            'can_persist_from_readiness' => false,
            'errors' => (array) ($diagnostic['errors'] ?? []),
            'violation_count' => (int) ($diagnostic['violation_count'] ?? 0),
            'placeholders' => (array) ($diagnostic['placeholders'] ?? []),
            'forbidden_flags_true' => (array) ($diagnostic['forbidden_flags_true'] ?? []),
            'persist_command' => $persistCommand,
            'detailed_endgame_command' => $detailedEndgameCommand,
            'pre_persist_operator_checks' => [
                'payload_json_sha256_matches_saved_file',
                'payload_hash_matches_canonical_payload_hash',
                'verifier_status_is_passed',
                'ready_for_explicit_operator_persistence_is_true',
                'persist_command_contains_explicit_persist_flag',
                'readiness_surface_is_not_the_persistence_surface',
                'completion_audit_must_be_rerun_after_persistence',
            ],
            'post_persistence_rerun_commands' => $postPersistenceCommands,
            'non_execution_guarantees' => [
                'envelope_readiness_does_not_persist_payload',
                'envelope_readiness_does_not_sign_for_operator',
                'envelope_readiness_does_not_call_provider',
                'envelope_readiness_does_not_spend_tokens',
                'envelope_readiness_does_not_dispatch',
                'envelope_readiness_does_not_enable_runtime',
                'envelope_readiness_does_not_promote_completion',
            ],
        ];
        if ($context !== []) {
            $envelope['current_evidence_context'] = $context;
        }
        $envelope['operator_submission_envelope_hash'] = $this->stableHash($envelope);

        return $envelope;
    }

    /** @param array<string, mixed> $diagnostic */
    private function envelopeStatus(string $artifact, array $diagnostic): string
    {
        if (($diagnostic['supplied'] ?? false) !== true) {
            return match ($artifact) {
                'runtime_promotion_receipt' => 'blocked_until_operator_runtime_promotion_receipt_exists',
                'real_provider_smoke' => 'blocked_until_operator_real_provider_smoke_payload_exists',
                'human_completion_receipt' => 'blocked_until_operator_human_completion_receipt_exists',
                default => 'blocked_until_operator_payload_exists',
            };
        }
        if (($diagnostic['ready'] ?? false) === true) {
            return 'ready_for_explicit_operator_persistence';
        }

        return match ($artifact) {
            'human_completion_receipt' => in_array('human_completion_receipt_supplied_before_runtime_and_smoke_green', (array) ($diagnostic['errors'] ?? []), true)
                ? 'blocked_until_runtime_and_smoke_envelopes_are_green'
                : 'blocked_until_human_completion_receipt_verifier_passes',
            'real_provider_smoke' => 'blocked_until_real_provider_smoke_verifier_passes',
            'runtime_promotion_receipt' => 'blocked_until_runtime_promotion_receipt_verifier_passes',
            default => 'blocked_until_verifier_passes',
        };
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  list<string>  $placeholders
     * @param  list<string>  $forbiddenFlagsTrue
     * @param  list<string>  $forbiddenFlagList
     * @return array<string, mixed>
     */
    private function diagnosticRow(
        bool $supplied,
        array $verification,
        string $composedHash,
        array $placeholders,
        array $forbiddenFlagsTrue,
        array $forbiddenFlagList,
    ): array {
        $status = (string) ($verification['status'] ?? 'not_supplied');
        $ready = $supplied && $status === 'passed' && $placeholders === [] && $forbiddenFlagsTrue === [];
        $errors = [];
        if (! $supplied) {
            $errors[] = 'not_supplied';
        } else {
            if ($status !== 'passed') {
                $errors[] = 'verifier_status_'.$status;
            }
            foreach ((array) data_get($verification, 'violations', []) as $violation) {
                $code = (string) data_get($violation, 'code', '');
                if ($code !== '') {
                    $errors[] = 'violation_code_'.$code;
                }
            }
            foreach ($placeholders as $field) {
                $errors[] = 'placeholder_field_'.$field;
            }
            foreach ($forbiddenFlagsTrue as $flag) {
                $errors[] = 'forbidden_flag_true_'.$flag;
            }
        }

        return [
            'supplied' => $supplied,
            'status' => $status,
            'ready' => $ready,
            'composed_hash' => $composedHash,
            'placeholders' => $placeholders,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'forbidden_flag_list' => $forbiddenFlagList,
            'violations' => (array) data_get($verification, 'violations', []),
            'violation_codes' => array_values(array_filter(array_map(
                static fn (mixed $violation): string => (string) data_get($violation, 'code', ''),
                (array) data_get($verification, 'violations', []),
            ))),
            'violation_count' => (int) data_get($verification, 'violation_count', 0),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return list<string> */
    private function missingProviderEvidence(array $smoke): array
    {
        return OperatorEvidence\OperatorEvidenceFieldInspector::missingProviderEvidence($smoke);
    }

    private function isPlaceholderValue(string $value): bool
    {
        return OperatorEvidence\OperatorEvidenceFieldInspector::isPlaceholderValue($value);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, string>
     */
    private function humanContextFromOptions(array $options, array $runtimeGapMatrix, array $runtimeReceipt, array $realProviderSmoke, array $completionAudit): array
    {
        return OperatorEvidence\OperatorEvidenceFieldInspector::humanContextFromOptions($options, $runtimeGapMatrix, $runtimeReceipt, $realProviderSmoke, $completionAudit);
    }

    /**
     * @param  array<string, mixed>  $runtimeReceipt
     * @return list<string>
     */
    private function staleContextHashes(array $runtimeReceipt, string $currentRuntimeGapMatrixHashForPromotionReceipt, string $currentRuntimePromotionBasisHash, string $currentRuntimePromotionClosureBasisHash): array
    {
        return OperatorEvidence\OperatorEvidenceFieldInspector::staleContextHashes($runtimeReceipt, $currentRuntimeGapMatrixHashForPromotionReceipt, $currentRuntimePromotionBasisHash, $currentRuntimePromotionClosureBasisHash);
    }

    private function nextRequiredCommand(string $nextRequired): string
    {
        return match ($nextRequired) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            default => '',
        };
    }

    /** @return list<array<string, mixed>> */
    private function closureArtifactSequence(bool $runtimePassed, bool $smokePassed, bool $humanPassed, bool $completionAuditReady): array
    {
        return [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => $runtimePassed ? 'passed' : 'blocked',
                'passed' => $runtimePassed,
                'expected_receipt_schema' => 'atlas.self_construction.runtime_promotion_receipt.v1',
                'draft_command' => $this->nextRequiredCommand('runtime_promotion_receipt'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'evidence_source' => 'runtime_gap_matrix',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 2,
                'artifact' => 'real_provider_smoke',
                'requirement' => 'end_to_end_real_provider_smoke_green',
                'blocker_type' => 'real_provider',
                'status' => $smokePassed ? 'passed' : 'blocked',
                'passed' => $smokePassed,
                'expected_receipt_schema' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'draft_command' => $this->nextRequiredCommand('real_provider_smoke'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json --persist-completion-evidence --json',
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => $humanPassed ? 'passed' : 'blocked',
                'passed' => $humanPassed,
                'expected_receipt_schema' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'draft_command' => $this->nextRequiredCommand('human_completion_receipt'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/human-completion-receipt.json --persist-completion-evidence --json',
                'evidence_source' => 'human_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => $completionAuditReady ? 'none' : 'derived',
                'status' => $completionAuditReady ? 'passed' : 'blocked_until_operator_evidence_green',
                'passed' => $completionAuditReady,
                'expected_receipt_schema' => 'atlas.self_construction.os_completion_audit.v1',
                'draft_command' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'persist_command' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
    }

    /**
     * Orders the four closure steps with dependencies, readiness, the
     * canonical command, and who owns running it. The worker can only
     * advance rerun_completion_audit on its own; the other three steps
     * require an operator signature or an externally-observed provider
     * call and are explicitly marked non-worker.
     *
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @return array<string, mixed>
     */
    private function nextActionGraph(bool $runtimePassed, bool $smokePassed, bool $humanPassed, array $diagnostics): array
    {
        $completionAuditReady = $runtimePassed && $smokePassed && $humanPassed;

        $nodes = [
            [
                'id' => 'runtime_promotion_receipt',
                'order' => 1,
                'depends_on' => [],
                'ready' => $runtimePassed,
                'blocking_reasons' => $runtimePassed ? [] : (array) data_get($diagnostics, 'runtime_promotion_receipt.errors', ['runtime_promotion_receipt_not_ready']),
                'canonical_command' => $this->nextRequiredCommand('runtime_promotion_receipt'),
                'worker_or_operator_owner' => 'operator',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'id' => 'real_provider_smoke',
                'order' => 2,
                'depends_on' => ['runtime_promotion_receipt'],
                'ready' => $smokePassed,
                'blocking_reasons' => $smokePassed ? [] : (array) data_get($diagnostics, 'real_provider_smoke.errors', ['real_provider_smoke_not_ready']),
                'canonical_command' => $this->nextRequiredCommand('real_provider_smoke'),
                'worker_or_operator_owner' => 'provider',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'id' => 'human_completion_receipt',
                'order' => 3,
                'depends_on' => ['runtime_promotion_receipt', 'real_provider_smoke'],
                'ready' => $humanPassed,
                'blocking_reasons' => $humanPassed ? [] : (array) data_get($diagnostics, 'human_completion_receipt.errors', ['human_completion_receipt_not_ready']),
                'canonical_command' => $this->nextRequiredCommand('human_completion_receipt'),
                'worker_or_operator_owner' => 'operator',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'id' => 'rerun_completion_audit',
                'order' => 4,
                'depends_on' => ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
                'ready' => $completionAuditReady,
                'blocking_reasons' => $completionAuditReady ? [] : ['prior_closure_steps_not_all_green'],
                'canonical_command' => $this->nextRequiredCommand('rerun_completion_audit'),
                'worker_or_operator_owner' => 'worker',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];

        $graph = [
            'schema_version' => 'atlas.self_construction.operator_evidence_next_action_graph.v1',
            'mode' => 'read_only_operator_evidence_next_action_graph',
            'status' => $completionAuditReady ? 'ready_for_completion_audit_rerun' : 'blocked_on_operator_or_provider_owned_steps',
            'nodes' => $nodes,
            'node_count' => count($nodes),
            'can_run_from_graph' => false,
            'non_execution_guarantees' => [
                'next_action_graph_does_not_execute_command',
                'next_action_graph_does_not_persist_receipts',
                'next_action_graph_does_not_call_provider',
                'next_action_graph_does_not_spend_tokens',
                'next_action_graph_does_not_sign_for_operator',
                'next_action_graph_does_not_promote_completion',
            ],
        ];
        $graph['next_action_graph_hash'] = $this->stableHash($graph);

        return $graph;
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
        ], $closureArtifactSequence);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @return array<string, mixed>
     */
    private function externalCompletionClaimPolicy(array $completionAudit, array $closureArtifactSequence, string $nextRequired): array
    {
        $auditComplete = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false)
            && (int) data_get($completionAudit, 'failed_count', count((array) data_get($completionAudit, 'failed_criteria', []))) === 0;
        $missingArtifacts = array_values(array_map(
            static fn (array $row): array => [
                'artifact' => (string) $row['artifact'],
                'requirement' => (string) $row['requirement'],
                'status' => (string) $row['status'],
                'blocker_type' => (string) $row['blocker_type'],
            ],
            array_filter(
                $closureArtifactSequence,
                static fn (array $row): bool => ! (bool) $row['passed'],
            ),
        ));

        $policy = [
            'schema_version' => 'atlas.self_construction.external_completion_claim_policy.v1',
            'mode' => 'read_only_external_completion_claim_policy',
            'status' => $auditComplete ? 'audit_authorizes_completion_claim' : 'reject_external_completion_claim',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_audit' => false,
            'completion_claim_allowed_by_audit' => $auditComplete,
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'current_completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'current_completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'current_failed_count' => (int) data_get($completionAudit, 'failed_count', count((array) data_get($completionAudit, 'failed_criteria', []))),
            'current_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'current_required_operator_artifact' => $nextRequired,
            'missing_required_evidence_artifacts' => $missingArtifacts,
            'missing_required_evidence_artifact_count' => count($missingArtifacts),
            'operator_verification_commands' => [
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit_with_canonical_terminal_loop_operational_proof' => $this->completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            ],
            'failure_policy' => [
                'ignore_external_agent_completion_claim_until_completion_audit_complete',
                'stop_if_failed_criteria_is_not_empty',
                'stop_if_completion_allowed_is_false',
                'stop_if_human_receipt_or_real_provider_smoke_is_missing',
                'rerun_operator_evidence_readiness_after_every_persisted_artifact',
            ],
            'non_execution_guarantees' => [
                'external_completion_claim_policy_does_not_persist_receipts',
                'external_completion_claim_policy_does_not_sign_for_operator',
                'external_completion_claim_policy_does_not_call_provider',
                'external_completion_claim_policy_does_not_spend_tokens',
                'external_completion_claim_policy_does_not_dispatch',
                'external_completion_claim_policy_does_not_enable_runtime',
                'external_completion_claim_policy_does_not_promote_completion',
            ],
        ];
        $policy['external_completion_claim_policy_hash'] = $this->stableHash($policy);

        return $policy;
    }

    /** @return list<string> */
    private function placeholderFieldsFromCommand(string $command): array
    {
        return OperatorEvidence\OperatorEvidenceCanonicalizer::placeholderFieldsFromCommand($command);
    }

    private function normalizeStoragePath(string $path): string
    {
        return OperatorEvidence\OperatorEvidenceCanonicalizer::normalizeStoragePath($path);
    }

    /** @return array<string, mixed> */
    private function emptyVerification(string $reason): array
    {
        return OperatorEvidence\OperatorEvidenceCanonicalizer::emptyVerification($reason);
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        return OperatorEvidence\OperatorEvidenceCanonicalizer::stableHash($payload);
    }

    /** @param array<string, mixed> $value */
}
