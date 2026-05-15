<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
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
    public const SCHEMA_VERSION = 'atlas.self_construction.operator_evidence_submission_readiness.v1';

    public const MODE = 'read_only_operator_evidence_submission_readiness';

    private const STORAGE_DISK = 'local';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $workspaceInput = $this->loadDraftWorkspaceInput((string) ($options['operator_draft_workspace_path'] ?? ''));
        $workspacePayloads = (array) data_get($workspaceInput, 'payloads', []);

        $runtimeReceipt = $this->payloadOrWorkspace(
            explicit: (array) ($options['runtime_promotion_receipt'] ?? []),
            workspacePayloads: $workspacePayloads,
            workspaceKey: 'runtime_promotion_receipt',
        );
        $realProviderSmoke = $this->payloadOrWorkspace(
            explicit: (array) ($options['real_provider_smoke'] ?? []),
            workspacePayloads: $workspacePayloads,
            workspaceKey: 'real_provider_smoke',
        );
        $completionReceipt = $this->payloadOrWorkspace(
            explicit: (array) ($options['completion_receipt'] ?? []),
            workspacePayloads: $workspacePayloads,
            workspaceKey: 'human_completion_receipt',
        );

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

        $humanContext = $this->humanContextFromOptions($options, $runtimeGapMatrix, $runtimeReceipt, $realProviderSmoke);
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
            'diagnostics' => $diagnostics,
            'operator_submission_envelopes' => $operatorSubmissionEnvelopes,
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
                'operator_evidence_submission_readiness_does_not_persist_operator_submission_envelopes',
                'operator_evidence_submission_readiness_reads_draft_workspace_without_marking_it_as_evidence',
            ],
        ];
        $payload['submission_readiness_hash'] = $this->stableHash($payload);

        return $payload;
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
     * @return array<string, mixed>
     */
    private function payloadOrWorkspace(array $explicit, array $workspacePayloads, string $workspaceKey): array
    {
        if ($explicit !== []) {
            return $explicit;
        }

        return (array) data_get($workspacePayloads, $workspaceKey, []);
    }

    /** @return array<string, mixed> */
    private function loadDraftWorkspaceInput(string $requestedPath): array
    {
        if (trim($requestedPath) === '') {
            return [
                'status' => 'not_requested',
                'requested_path' => '',
                'manifest_path' => '',
                'artifact_count' => 0,
                'loaded_artifacts' => [],
                'violations' => [],
                'warnings' => [],
                'payloads' => [],
            ];
        }

        $inspection = (new AtlasSelfConstructionOperatorEvidenceDraftWorkspaceInspectorService)->inspect([
            'operator_draft_workspace_path' => $requestedPath,
        ]);
        $payloads = [];
        $violations = (array) data_get($inspection, 'violations', []);
        $warnings = (array) data_get($inspection, 'warnings', []);

        foreach ((array) data_get($inspection, 'files', []) as $file) {
            $artifact = (string) data_get($file, 'artifact', '');
            $draftPath = $this->normalizeStoragePath((string) data_get($file, 'draft_path', ''));
            if ($artifact === '' || $draftPath === '' || ! Storage::disk(self::STORAGE_DISK)->exists($draftPath)) {
                continue;
            }

            try {
                $decoded = json_decode(Storage::disk(self::STORAGE_DISK)->get($draftPath), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payloads[$artifact] = $decoded;
                }
            } catch (\Throwable) {
                $violations[] = 'draft_workspace_payload_invalid_json:'.$artifact;
            }
        }

        return [
            'status' => data_get($inspection, 'status') === 'no_workspace'
                ? 'workspace_not_found'
                : 'loaded_for_read_only_submission_readiness',
            'requested_path' => $requestedPath,
            'manifest_path' => (string) data_get($inspection, 'manifest_path', ''),
            'workspace_directory' => (string) data_get($inspection, 'workspace_directory', ''),
            'artifact_count' => (int) data_get($inspection, 'artifact_count', 0),
            'loaded_artifacts' => array_keys($payloads),
            'inspector_status' => (string) data_get($inspection, 'status', ''),
            'inspector_hash' => (string) data_get($inspection, 'inspector_hash', ''),
            'workspace_safe_for_operator_editing' => (bool) data_get($inspection, 'workspace_safe_for_operator_editing', false),
            'violation_count' => count(array_values(array_unique($violations))),
            'violations' => array_values(array_unique($violations)),
            'warning_count' => count(array_values(array_unique($warnings))),
            'warnings' => array_values(array_unique($warnings)),
            'payloads' => $payloads,
            'read_only' => true,
            'draft_is_evidence' => false,
            'can_persist_draft_directly' => false,
        ];
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
            'violation_count' => (int) data_get($verification, 'violation_count', 0),
            'errors' => $errors,
        ];
    }

    /** @return list<string> */
    private function missingProviderEvidence(array $smoke): array
    {
        if ($smoke === []) {
            return [];
        }
        $missing = [];
        foreach ([
            'provider_run_id',
            'task_packet_id',
            'observed_by',
            'approval_reason',
            'cost_event_hash',
            'work_product_manifest_hash',
            'evidence_ledger_hash',
            'continuation_summary_hash',
            'provider_response_hash',
            'operator_approval_receipt_hash',
        ] as $field) {
            $value = trim((string) ($smoke[$field] ?? ''));
            if ($value === '' || str_starts_with($value, '<')) {
                $missing[] = 'missing_or_placeholder_'.$field;
            }
        }
        foreach ([
            'provider_call_observed',
            'token_spend_observed',
            'claim_to_completion_observed',
            'work_product_collected',
            'operator_supplied_evidence',
            'real_provider_run_observed_by_operator',
        ] as $flag) {
            if (($smoke[$flag] ?? false) !== true) {
                $missing[] = 'missing_observation_flag_'.$flag;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @param  array<string, mixed>  $runtimeReceipt
     * @param  array<string, mixed>  $realProviderSmoke
     * @return array<string, string>
     */
    private function humanContextFromOptions(array $options, array $runtimeGapMatrix, array $runtimeReceipt, array $realProviderSmoke): array
    {
        $explicitContext = (array) ($options['human_completion_receipt_context'] ?? []);

        return [
            'completion_audit_hash' => (string) ($explicitContext['completion_audit_hash'] ?? ''),
            'release_dossier_hash' => (string) ($explicitContext['release_dossier_hash'] ?? ''),
            'replay_diff_hash' => (string) ($explicitContext['replay_diff_hash'] ?? ''),
            'runtime_gap_matrix_hash' => (string) ($explicitContext['runtime_gap_matrix_hash'] ?? data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) ($explicitContext['runtime_promotion_receipt_hash'] ?? data_get($runtimeReceipt, 'receipt_hash', '')),
            'real_provider_smoke_hash' => (string) ($explicitContext['real_provider_smoke_hash'] ?? data_get($realProviderSmoke, 'smoke_hash', '')),
            'certification_status_batch_hash' => (string) ($explicitContext['certification_status_batch_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeReceipt
     * @return list<string>
     */
    private function staleContextHashes(array $runtimeReceipt, string $currentRuntimeGapMatrixHashForPromotionReceipt, string $currentRuntimePromotionBasisHash, string $currentRuntimePromotionClosureBasisHash): array
    {
        if ($runtimeReceipt === []) {
            return [];
        }
        $stale = [];
        $candidates = [
            'runtime_gap_matrix_hash' => $currentRuntimeGapMatrixHashForPromotionReceipt,
            'runtime_promotion_basis_hash' => $currentRuntimePromotionBasisHash,
            'runtime_promotion_closure_basis_hash' => $currentRuntimePromotionClosureBasisHash,
        ];
        foreach ($candidates as $field => $current) {
            if ($current === '') {
                continue;
            }
            $value = (string) ($runtimeReceipt[$field] ?? '');
            if ($value !== '' && $value !== $current) {
                $stale[] = $field;
            }
        }

        return $stale;
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

    private function normalizeStoragePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        $path = preg_replace('#^storage/app/#', '', $path) ?? $path;

        return trim($path, '/');
    }

    /** @return array<string, mixed> */
    private function emptyVerification(string $reason): array
    {
        return [
            'status' => 'not_supplied',
            'reason' => $reason,
            'violations' => [],
            'violation_count' => 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['submission_readiness_hash']);
        unset($payload['diagnostics']['runtime_promotion_receipt']['violations']);
        unset($payload['diagnostics']['real_provider_smoke']['violations']);
        unset($payload['diagnostics']['human_completion_receipt']['violations']);

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
