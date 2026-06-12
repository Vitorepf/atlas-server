<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\Context\AtlasAucriRuntimeEnforcementService;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasMinimaxFirstWorkerService;
use App\Services\Ai\Programming\AtlasForgeCodexCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Services\Ai\Programming\HermesWorkspaceDefaults;
use App\Services\Ai\Programming\AtlasDev\Gate\AtlasDevVerificationCommandRunnerContract as VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\PatchIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Process\Process;

/**
 * Default HTTP-side {@see RunExecutor} that ties the core run-path services
 * end-to-end. Core (Pipeline/Gate/Provider) stays surface-agnostic; this
 * class is the surface boundary that knows how to drive them.
 *
 * Production wiring requires {@see ClaudeCliGateway} and {@see VerificationCommandRunner}
 * bindings in the container. When either is missing the executor returns a
 * blocked execution result instead of crashing, signalling that the surface
 * (Desktop/CLI/App) should treat the run as ready-but-not-runnable until
 * those drivers are configured.
 *
 * Tests bind a fake gateway + fake command runner via the service container
 * before hitting the HTTP route; this executor honours those bindings.
 */
final class PipelineRunExecutor implements RunExecutor
{
    public function __construct(
        private readonly Container $container,
        private readonly ReceiptStorage $storage,
    ) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        // F-03: derive task_kind / risk_level from the persisted CompactSDD
        // BEFORE the provider is invoked. If it is missing, invalid, or its
        // canonical hash no longer matches the value pinned at Plan time, we
        // fail closed (CompactSddUnavailableException → 422) instead of
        // wasting a provider call on a run we cannot honestly attest.
        [$taskKind, $riskLevel] = $this->resolveTaskKindAndRiskLevel($runId, $expectedCompactSddHash);

        $aucriEnforcement = $this->enforceAucriBeforeProvider(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            runId: $runId,
            riskLevel: $riskLevel,
            taskKind: $taskKind,
        );
        if (($aucriEnforcement['status'] ?? 'blocked') !== 'passed') {
            return $this->blockedDueToAucri($aucriEnforcement);
        }

        $commandRunner = $this->resolve(VerificationCommandRunner::class);
        $deterministicCallResult = $this->deterministicFastPathEnabled()
            ? $this->tryDeterministicPatch($envelope, $taskContract, $runId)
            : null;

        if ($commandRunner === null) {
            return $this->blockedDueToUnwiredDrivers($envelope, false, true, $taskContract->providerLock->provider, $taskContract->providerLock->modelFamily);
        }

        $callResult = $deterministicCallResult;
        $providerCalls = 0;
        if ($callResult === null) {
            [$callResult, $providerCalls] = $this->executeLockedProvider(
                envelope: $envelope,
                taskContract: $taskContract,
                promptProjection: $promptProjection,
            );
        }

        $diffResult = (new DiffParser)->parse($callResult->stdout);

        $persisted = [];
        $persisted[ArtifactNames::PROVIDER_CALL_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::PROVIDER_CALL_RESULT,
            $callResult->toCanonicalArray(),
        );
        $persisted[ArtifactNames::DIFF_PARSE_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::DIFF_PARSE_RESULT,
            $diffResult->toCanonicalArray(),
        );

        $scopeReceipt = (new ScopeGuard)->check(
            envelope: $envelope,
            taskContract: $taskContract,
            diffResult: $diffResult,
        );

        $patchApplyResult = $this->applyPatchIfSafe(
            diffResult: $diffResult,
            scopeStatus: $scopeReceipt->status,
            workspace: $envelope->workspace,
            callResult: $callResult,
        );
        $callResultForGates = $patchApplyResult->ok()
            ? $callResult
            : $this->withProviderError($callResult, 'patch_apply_failed');

        $verificationResult = $patchApplyResult->ok()
            ? (new VerificationGate($commandRunner, $this->verificationReceiptStorage($runId)))->run(
                taskContract: $taskContract,
                callResult: $callResultForGates,
                scopeReceipt: $scopeReceipt,
                workspace: $envelope->workspace,
            )
            : $this->verificationFailedDueToPatchApply($patchApplyResult);

        $decision = (new CompletionStateGate)->decide(
            taskContract: $taskContract,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            callResult: $callResultForGates,
            diffResult: $diffResult,
        );

        $receipt = (new ReceiptComposer)->compose(
            envelope: $envelope,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            callResult: $callResultForGates,
            diffResult: $diffResult,
            scopeReceipt: $scopeReceipt,
            verificationResult: $verificationResult,
            completion: $decision,
            contextPackHash: $this->contextPackHash($runId),
            taskKind: $taskKind,
            riskLevel: $riskLevel,
            modelLabel: $callResult->actualProvider.':'.$callResult->actualModelFamily,
        );

        $persisted[ArtifactNames::PATCH_APPLY_RESULT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::PATCH_APPLY_RESULT,
            $patchApplyResult->toArray(),
        );
        $persisted[ArtifactNames::SCOPE_GUARD_RECEIPT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::SCOPE_GUARD_RECEIPT,
            $scopeReceipt->toCanonicalArray(),
        );
        $persisted[ArtifactNames::VERIFICATION_RECEIPT] = $this->storage->writeAtomic(
            $runId,
            ArtifactNames::VERIFICATION_RECEIPT,
            $receipt->toCanonicalArray(),
        );

        // Patch/Test Intelligence — derives risk, blast radius, focused tests
        // and rollback hints from the scope-guard observation. Strictly
        // additive: failures are swallowed so they cannot regress the gate
        // outcome the rest of the pipeline already produced.
        try {
            $patchIntelligence = (new PatchIntelligenceService)->analyze(new PatchIntelligenceInput(
                runId: $runId,
                taskContractHash: $scopeReceipt->taskContractHash,
                expectedFiles: $taskContract->allowedFiles,
                changedFiles: $scopeReceipt->observed->fileDiffs,
                userPreExistingChanges: $scopeReceipt->userPreExistingChanges,
                evidenceRefs: [],
            ));
            $persisted[ArtifactNames::PATCH_INTELLIGENCE_RECEIPT] = $this->storage->writeAtomic(
                $runId,
                ArtifactNames::PATCH_INTELLIGENCE_RECEIPT,
                $patchIntelligence->toCanonicalArray(),
            );

            $testSelection = (new TestSelectionIntelligenceService)->select(new TestSelectionInput(
                runId: $runId,
                taskContractHash: $scopeReceipt->taskContractHash,
                changedFiles: array_map(
                    static fn ($diff): string => $diff->path,
                    $scopeReceipt->observed->fileDiffs,
                ),
                expectedTests: $taskContract->validationCommands,
                riskLevel: $patchIntelligence->riskLevel,
                evidenceRefs: [],
            ));
            $persisted[ArtifactNames::TEST_SELECTION_RECEIPT] = $this->storage->writeAtomic(
                $runId,
                ArtifactNames::TEST_SELECTION_RECEIPT,
                $testSelection->toCanonicalArray(),
            );
        } catch (\Throwable) {
            // Patch/test intelligence is advisory. Swallow so it never
            // shadows the canonical receipts already persisted above.
        }

        return new RunExecutionResult(
            completionState: $receipt->completion->status,
            scopeGuardStatus: $scopeReceipt->status,
            verificationStatus: $verificationResult->aggregateStatus,
            persistedReceiptPaths: $persisted,
            providerCallSummary: [
                'provider' => $callResult->actualProvider,
                'model_family' => $callResult->actualModelFamily,
                'provider_calls' => $providerCalls,
                'exit_code' => $callResultForGates->exitStatus,
                'duration_ms' => $callResultForGates->durationMs,
                'tokens_in' => $callResultForGates->tokensIn,
                'tokens_out' => $callResultForGates->tokensOut,
                'estimated_cost_usd' => $callResultForGates->costEstimateUsd,
                'error_codes' => array_values($callResultForGates->errors),
                'raw_response_hash' => $callResult->rawResponseHash,
                'stdout_bytes' => strlen($callResult->stdout),
                'stderr_bytes' => strlen($callResult->stderr),
            ],
            diffParseSummary: $diffResult->toSummaryArray(),
            verificationReceiptHash: $receipt->receiptHash,
            scopeGuardReceiptHash: $scopeReceipt->receiptHash,
            diffHash: $diffResult->diffHash(),
        );
    }

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeLockedProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        if (! $promptProjection->isSendable()) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'prompt_projection_not_sendable:'.implode(',', $promptProjection->qualityChecks->failedChecks()),
                    stderr: 'Atlas Dev refused to dispatch provider because ProviderPromptProjection is not sendable.',
                    providerSafe: false,
                ),
                0,
            ];
        }

        return match ($taskContract->providerLock->provider) {
            SonnetClaudeCliAdapter::PROVIDER => $this->executeClaudeProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCodexCliInvocationDriver::PROVIDER => $this->executeCodexProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeCursorCliInvocationDriver::PROVIDER => $this->executeCursorProvider($envelope, $taskContract, $promptProjection),
            AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER => $this->executeMinimaxProvider($envelope, $taskContract, $promptProjection),
            'hermes_cli' => $this->executeHermesProvider($envelope, $taskContract, $promptProjection),
            default => [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: $taskContract->providerLock->provider,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'unsupported_provider_lock:'.$taskContract->providerLock->provider,
                    stderr: 'Atlas Dev has no runtime driver for provider_lock.provider='.$taskContract->providerLock->provider.'.',
                ),
                0,
            ],
        };
    }

    /**
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeClaudeProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $gateway = $this->resolve(ClaudeCliGateway::class);
        if (! $gateway instanceof ClaudeCliGateway) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: SonnetClaudeCliAdapter::PROVIDER,
                    modelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
                    error: 'claude_cli_gateway_unbound',
                    stderr: 'Claude CLI gateway is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $adapter = new SonnetClaudeCliAdapter($gateway);

        return [
            $adapter->executeOneCall(
                promptProjection: $promptProjection,
                taskContract: $taskContract,
                workspace: $envelope->workspace,
                timeoutSeconds: $this->providerTimeoutSeconds($taskContract),
            ),
            1,
        ];
    }

    /**
     * Codex CLI is used as a governed workspace mutator for review/repair gates.
     * Atlas derives the diff after Codex returns and still runs scope +
     * verification before a completion claim can be promoted.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeCodexProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolveConcrete(AtlasForgeCodexCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCodexCliInvocationDriver) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'codex_cli_driver_unavailable',
                    stderr: 'Codex CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));
        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.codex_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'review/repair current workspace diff; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'sandbox' => 'workspace-write',
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $providerChangedFiles = $this->stringList((array) ($result['changed_files'] ?? []));
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $blockers[] = 'codex_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $errors = array_values(array_unique($blockers));
        if ($errors === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Codex CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Codex CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCodexCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * Cursor CLI is a governed workspace mutator: unlike the Claude adapter it
     * edits the isolated worktree directly. We therefore convert the post-run
     * git diff into the ProviderCallResult stdout and later skip re-applying it.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeCursorProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $driver = $this->resolveConcrete(AtlasForgeCursorCliInvocationDriver::class);
        if (! $driver instanceof AtlasForgeCursorCliInvocationDriver) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'cursor_cli_driver_unavailable',
                    stderr: 'Cursor CLI invocation driver could not be resolved.',
                ),
                0,
            ];
        }

        $decisionReceiptId = 'atlas-dev:'.$promptProjection->runId.':'.$taskContract->taskContractHash;
        $decisionReceiptHash = hash('sha256', implode('|', [
            $promptProjection->runId,
            $taskContract->taskContractHash,
            $promptProjection->promptProjectionHash,
            $taskContract->providerLock->provider,
            $taskContract->providerLock->modelFamily,
        ]));

        $request = [
            'model' => $taskContract->providerLock->modelFamily,
            'prompt' => [
                'schema_version' => 'atlas.dev.cursor_cli.provider_request.v1',
                'decision_receipt_id' => $decisionReceiptId,
                'decision_receipt_hash' => $decisionReceiptHash,
                'atlas_dev_contract' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                    'output_required' => 'mutate only allowed files; Atlas will derive git diff and run validation.',
                ],
                'scope_contract' => [
                    'allowed_files' => array_values($taskContract->allowedFiles),
                    'forbidden_files' => array_values($taskContract->forbiddenFiles),
                    'max_files_changed' => $taskContract->maxFilesChanged,
                ],
                'rendered_prompt_text' => $promptProjection->renderedPromptText,
            ],
            'cwd' => $envelope->workspace,
            'decision_receipt_id' => $decisionReceiptId,
            'decision_receipt_hash' => $decisionReceiptHash,
            'timeout_seconds' => $this->providerTimeoutSeconds($taskContract),
            'max_output_chars' => $this->providerMaxOutputChars($taskContract),
        ];

        $result = $driver->invoke($request);
        $providerCalled = (bool) ($result['provider_called'] ?? false);
        $blockers = array_values(array_filter(array_map(
            static fn (mixed $blocker): string => is_string($blocker) ? $blocker : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $blocker): bool => $blocker !== ''));
        $scopeViolations = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? $path : '',
            (array) ($result['scope_violations'] ?? []),
        ), static fn (string $path): bool => $path !== ''));

        $exitCode = is_int($result['exit_code'] ?? null) ? (int) $result['exit_code'] : ($blockers === [] ? 0 : 1);
        $stdout = '';
        $errors = $blockers;
        if ($scopeViolations !== []) {
            $errors[] = 'cursor_cli_scope_violations:'.implode(',', $scopeViolations);
        }
        if ($blockers === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Cursor CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Cursor CLI runtime blocked: ".implode(',', $blockers)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeCursorCliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $exitCode,
                stdout: $stdout,
                stderr: trim((string) ($result['stderr_excerpt'] ?? '')),
                durationMs: is_int($result['duration_ms'] ?? null) ? (int) $result['duration_ms'] : 0,
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: $errors,
            ),
            $providerCalled ? 1 : 0,
        ];
    }

    /**
     * MiniMax M3 — writes files directly into the workspace (like Cursor).
     * The worker handles context compilation, invocation and repair loop.
     * We derive the git diff after writes complete and surface it as stdout.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeMinimaxProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $worker = $this->resolveConcrete(AtlasMinimaxFirstWorkerService::class);
        if (! $worker instanceof AtlasMinimaxFirstWorkerService) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'minimax_worker_unbound',
                    stderr: 'AtlasMinimaxFirstWorkerService is not bound in the runtime container.',
                ),
                0,
            ];
        }

        $finding = [
            'title'       => mb_substr($envelope->normalizedIntent, 0, 300),
            'description' => mb_substr($promptProjection->renderedPromptText, 0, 2_000),
            'spec_seed'   => ['candidate_id' => $taskContract->taskId],
        ];

        $startMs = (int) (microtime(true) * 1_000);
        $result  = $worker->run([
            'finding'             => $finding,
            'allowed_files'       => array_values($taskContract->allowedFiles),
            'validation_commands' => array_values($taskContract->validationCommands),
            'worktree_path'       => $envelope->workspace,
            'repo_root'           => $envelope->workspace,
            'max_repairs'         => $taskContract->repairPolicy->maxAttempts,
        ]);
        $durationMs = (int) (microtime(true) * 1_000) - $startMs;

        $status     = (string) ($result['status'] ?? 'blocked');
        $tokensUsed = (int) ($result['run_summary']['provider_call']['tokens_used'] ?? 0);
        $blockers   = array_values(array_filter(array_map(
            static fn (mixed $b): string => is_string($b) ? $b : '',
            (array) ($result['blockers'] ?? []),
        ), static fn (string $b): bool => $b !== ''));

        if ($status !== 'completed') {
            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: 'MiniMax worker: '.implode('; ', $blockers ?: [$status]),
                    durationMs: $durationMs,
                    tokensIn: $tokensUsed,
                    tokensOut: 0,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: $blockers ?: [$status],
                ),
                1,
            ];
        }

        // Worker wrote files directly — derive diff like Cursor provider.
        $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
        if (trim($stdout) === '') {
            $stdout = "no_patch_needed: true
reason: MiniMax worker completed without a workspace diff in allowed_files.
";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER,
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: 0,
                stdout: $stdout,
                stderr: '',
                durationMs: $durationMs,
                tokensIn: $tokensUsed,
                tokensOut: 0,
                costEstimateUsd: null,
                providerSafe: true,
            ),
            1,
        ];
    }

    /**
     * Hermes CLI is the Atlas executive runtime governed through
     * {@see \App\Services\Ai\HermesCliProvider}. Like Codex/Cursor/MiniMax it
     * mutates the isolated workspace directly, so Atlas derives the post-run
     * git diff and still runs scope + verification before any completion claim.
     *
     * The provider chooses its own cwd via
     * {@see \App\Services\Ai\Concerns\RunsCliProcesses::workdirForJob()}, which
     * reads (in order) payload.tool_permissions.workspace, payload.workspace,
     * then config('atlas.ai.workdir') — realpath()'d and required to be a dir.
     * We therefore pin BOTH workspace keys to $envelope->workspace so Hermes
     * edits the Dev worktree and not the global Atlas workdir.
     *
     * @return array{0:ProviderCallResult,1:int}
     */
    private function executeHermesProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
    ): array {
        $manager = app(\App\Services\Ai\AiProviderManager::class);

        $provider = null;
        try {
            $provider = $manager->get('hermes_cli');
        } catch (\Throwable) {
            $provider = null;
        }
        if (! $provider instanceof \App\Services\Ai\AiProvider) {
            return [
                $this->blockedProviderCallResult(
                    runId: $promptProjection->runId,
                    provider: 'hermes_cli',
                    modelFamily: $taskContract->providerLock->modelFamily,
                    error: 'hermes_cli_provider_unavailable',
                    stderr: 'Hermes CLI provider could not be resolved from AiProviderManager.',
                ),
                0,
            ];
        }

        $timeoutSeconds = $this->providerTimeoutSeconds($taskContract);

        // workdirForJob() reads tool_permissions.workspace || workspace ||
        // config('atlas.ai.workdir'). Pin both so Hermes runs IN the Dev
        // worktree ($envelope->workspace) and edits files there.
        $job = new \App\Models\AiJob([
            'trace_id' => 'atlas-dev:'.$promptProjection->runId,
            'kind' => 'atlas_dev_run',
            'provider' => 'hermes_cli',
            // Hermes self-selects its sub-model; the _default sentinel makes its
            // CLI omit --model. Single-sourced so Dev/Forge can't diverge.
            'model' => HermesWorkspaceDefaults::model(),
            'prompt' => $promptProjection->renderedPromptText,
            'input_text' => $promptProjection->renderedPromptText,
            'timeout_seconds' => $timeoutSeconds,
            'payload' => [
                'workspace' => $envelope->workspace,
                // mode 'danger' → HermesCliProvider passes --yolo so Hermes edits the
                // isolated workspace AUTONOMOUSLY (a non-interactive run has no TTY to
                // approve writes); ScopeGuard + verification gate the result downstream.
                'tool_permissions' => HermesWorkspaceDefaults::toolPermissions($envelope->workspace),
                'dev_execution_plan' => [
                    'run_id' => $promptProjection->runId,
                    'task_contract_hash' => $taskContract->taskContractHash,
                    'prompt_projection_hash' => $promptProjection->promptProjectionHash,
                ],
            ],
        ]);

        $startMs = (int) (microtime(true) * 1_000);
        try {
            $result = $provider->run($job, $promptProjection->renderedPromptText);
        } catch (\Throwable $e) {
            return [
                ProviderCallResult::fromStdout(
                    runId: $promptProjection->runId,
                    actualProvider: 'hermes_cli',
                    actualModelFamily: $taskContract->providerLock->modelFamily,
                    exitStatus: 1,
                    stdout: '',
                    stderr: \Illuminate\Support\Str::limit($e->getMessage(), 500, '...'),
                    durationMs: (int) (microtime(true) * 1_000) - $startMs,
                    tokensIn: null,
                    tokensOut: null,
                    costEstimateUsd: null,
                    providerSafe: true,
                    errors: ['hermes_cli_invocation_threw'],
                ),
                1,
            ];
        }

        $errors = $result->ok ? [] : array_values(array_filter([
            is_string($result->errorCode) && $result->errorCode !== '' ? $result->errorCode : null,
        ]));

        // Hermes mutated the workspace directly — derive diff like the
        // Codex/Cursor/MiniMax providers and let scope/verification gate it.
        $providerChangedFiles = $errors === []
            ? $this->stringList($this->changedFilePathsInWorkspace(
                $envelope->workspace,
                $taskContract->allowedFiles,
                $taskContract->forbiddenFiles,
            ))
            : [];
        $scopeViolations = array_values(array_filter(
            $providerChangedFiles,
            fn (string $path): bool => ! $this->pathAllowed($path, $taskContract->allowedFiles),
        ));
        if ($scopeViolations !== []) {
            $errors[] = 'hermes_cli_scope_violation:'.implode(',', $scopeViolations);
        }

        if ($errors === []) {
            $stdout = $this->workspaceDiff($envelope->workspace, $taskContract->allowedFiles);
            if (trim($stdout) === '') {
                $stdout = "no_patch_needed: true\nreason: Hermes CLI completed without a workspace diff in allowed_files.\n";
            }
        } else {
            $stdout = "blocked: true\nquestion: Hermes CLI runtime blocked: ".implode(',', $errors)."\n";
        }

        return [
            ProviderCallResult::fromStdout(
                runId: $promptProjection->runId,
                actualProvider: 'hermes_cli',
                actualModelFamily: $taskContract->providerLock->modelFamily,
                exitStatus: $errors === [] ? 0 : 1,
                stdout: $stdout,
                stderr: '',
                durationMs: (int) ($result->durationMs ?? 0),
                tokensIn: null,
                tokensOut: null,
                costEstimateUsd: null,
                providerSafe: true,
                errors: array_values(array_unique($errors)),
            ),
            $result->ok ? 1 : 0,
        ];
    }

    private function blockedProviderCallResult(
        string $runId,
        string $provider,
        string $modelFamily,
        string $error,
        string $stderr,
        bool $providerSafe = true,
    ): ProviderCallResult {
        return ProviderCallResult::fromStdout(
            runId: $runId,
            actualProvider: $provider !== '' ? $provider : 'unknown',
            actualModelFamily: $modelFamily !== '' ? $modelFamily : 'unknown',
            exitStatus: 1,
            stdout: '',
            stderr: $stderr,
            durationMs: 0,
            tokensIn: null,
            tokensOut: null,
            costEstimateUsd: null,
            providerSafe: $providerSafe,
            errors: [$error],
        );
    }

    /**
     * Changed (tracked, staged, untracked, and explicit ignored-forbidden)
     * workspace paths, repo-relative, used to compute scope violations for
     * providers (like Hermes) that mutate the worktree directly but do not
     * return a structured changed-files list.
     *
     * Important: scope inspection must not pathspec regular untracked files to
     * allowed_files. Otherwise a provider can create an out-of-scope file and
     * still pass by also changing an allowed file. Staged files need their own
     * cached diff because `git add` removes them from the untracked set. Ignored
     * files are limited to explicit forbidden_files to avoid failing every real
     * workspace that already has ignored local artifacts such as dependency
     * folders or machine-local environment files.
     *
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @return list<string>
     */
    private function changedFilePathsInWorkspace(string $workspace, array $allowedFiles, array $forbiddenFiles = []): array
    {
        if (! is_dir($workspace)) {
            return [];
        }

        $paths = [];

        foreach ([
            ['git', 'diff', '--no-ext-diff', '--name-only'],
            ['git', 'diff', '--cached', '--no-ext-diff', '--name-only'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $paths = array_merge($paths, $this->gitNameOnlyPaths($workspace, $argv));
        }

        $ignoredForbidden = $this->safeRelativePaths($forbiddenFiles);
        if ($ignoredForbidden !== []) {
            $argv = ['git', 'ls-files', '--others', '--ignored', '--exclude-standard', '--'];
            array_push($argv, ...$ignoredForbidden);
            $paths = array_merge($paths, $this->gitNameOnlyPaths($workspace, $argv));
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function gitNameOnlyPaths(string $workspace, array $argv): array
    {
        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function safeRelativePaths(array $paths): array
    {
        return array_values(array_filter(array_map(
            static function (string $path): string {
                $path = ltrim(trim($path), '/');

                return $path !== '' && ! str_contains($path, '..') ? $path : '';
            },
            $paths,
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function workspaceDiff(string $workspace, array $allowedFiles): string
    {
        if (! is_dir($workspace)) {
            return '';
        }

        $paths = array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $allowedFiles,
        ), static fn (string $path): bool => $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..')));

        $argv = ['git', 'diff', '--no-ext-diff', '--'];
        array_push($argv, ...$paths);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
            return '';
        }

        $diff = (string) $process->getOutput();
        $diff .= $this->untrackedAllowedFilesDiff($workspace, $paths);

        return $diff !== '' && ! str_ends_with($diff, "\n") ? $diff."\n" : $diff;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     */
    private function pathAllowed(string $path, array $allowedFiles): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return false;
        }

        return in_array($path, $allowedFiles, true);
    }

    /**
     * git diff omits untracked files. Cursor-style workspace mutators often
     * satisfy missing-test findings by creating a brand-new allowed test file,
     * so Atlas must promote those files into a unified diff before parsing.
     *
     * @param  list<string>  $allowedFiles
     */
    private function untrackedAllowedFilesDiff(string $workspace, array $allowedFiles): string
    {
        if ($allowedFiles === []) {
            return '';
        }

        $argv = ['git', 'ls-files', '--others', '--exclude-standard', '--'];
        array_push($argv, ...$allowedFiles);

        $process = new Process($argv, $workspace, null, null, 15.0);
        $process->run();
        if (! $process->isSuccessful()) {
            return '';
        }

        $untracked = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            explode("\n", (string) $process->getOutput()),
        ), static fn (string $path): bool => $path !== ''));

        $diff = '';
        foreach ($untracked as $path) {
            if (! in_array($path, $allowedFiles, true)) {
                continue;
            }
            $filePath = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            if (! is_file($filePath)) {
                continue;
            }
            $fileDiff = new Process(['git', 'diff', '--no-index', '--', '/dev/null', $path], $workspace, null, null, 15.0);
            $fileDiff->run();
            $output = (string) $fileDiff->getOutput();
            if ($output === '') {
                continue;
            }
            $diff .= (str_ends_with($diff, "\n") || $diff === '' ? '' : "\n").$output;
        }

        return $diff;
    }

    private function tryDeterministicPatch(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        string $runId,
    ): ?ProviderCallResult {
        if ($taskContract->allowedFiles === [] || $taskContract->validationCommands === []) {
            return null;
        }

        $candidates = [];
        foreach ($taskContract->allowedFiles as $relativePath) {
            if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
                continue;
            }

            $absolutePath = rtrim($envelope->workspace, '/').'/'.$relativePath;
            if (! is_file($absolutePath)) {
                continue;
            }

            $original = (string) file_get_contents($absolutePath);
            $updated = $this->deterministicUpdatedContents($relativePath, $original, $envelope->normalizedIntent);
            if ($updated === null || $updated === $original) {
                continue;
            }

            $candidates[] = [$relativePath, $original, $updated];
        }

        if (count($candidates) !== 1) {
            return null;
        }

        [$relativePath, $original, $updated] = $candidates[0];
        $diff = $this->singleFileUnifiedDiff($relativePath, $original, $updated);
        if ($diff === '') {
            return null;
        }

        return ProviderCallResult::fromStdout(
            runId: $runId,
            actualProvider: 'atlas_deterministic',
            actualModelFamily: 'atlas_dev_fast_path',
            exitStatus: 0,
            stdout: $diff,
            stderr: '',
            durationMs: 0,
            tokensIn: 0,
            tokensOut: 0,
            costEstimateUsd: 0.0,
            providerSafe: true,
        );
    }

    private function deterministicFastPathEnabled(): bool
    {
        return (bool) config('atlas_dev.efficient.deterministic_fast_path_enabled', true);
    }

    private function deterministicUpdatedContents(string $relativePath, string $original, string $intent): ?string
    {
        $lower = strtolower($intent);
        if (str_ends_with($relativePath, '.php') && str_contains($lower, 'hello atlas')) {
            if (str_contains($original, 'helo atlas')) {
                return str_replace('helo atlas', 'hello atlas', $original);
            }

            return preg_replace(
                "/return\\s+(['\"])[^'\"]*\\1\\s*;/",
                "return 'hello atlas';",
                $original,
                1,
            ) ?: null;
        }

        if (preg_match('/\\.(?:css|html)\\z/i', $relativePath) === 1
            && preg_match('/background(?:-color)?\\s+#([0-9a-f]{3,6})/i', $intent, $background)
            && preg_match('/border-radius\\s+([0-9]+px)/i', $intent, $radius)) {
            return $this->upsertPrimaryButtonStyles(
                $original,
                '#'.strtolower($background[1]),
                strtolower($radius[1]),
            );
        }

        if (str_ends_with($relativePath, '.blade.php')
            && str_contains($lower, 'elevated')
            && str_contains($original, 'class="status-card compact"')) {
            return str_replace(
                'class="status-card compact"',
                'class="status-card compact elevated"',
                $original,
            );
        }

        return null;
    }

    private function upsertPrimaryButtonStyles(string $contents, string $background, string $radius): ?string
    {
        $pattern = '/(?P<head>\\.primary-button\\s*\\{)(?P<body>.*?)(?P<tail>\\})/s';
        if (preg_match($pattern, $contents) !== 1) {
            return null;
        }

        return preg_replace_callback($pattern, function (array $matches) use ($background, $radius): string {
            $body = (string) $matches['body'];
            $body = $this->upsertCssDeclaration($body, 'background', $background);
            $body = $this->upsertCssDeclaration($body, 'border-radius', $radius);

            return $matches['head'].$body.$matches['tail'];
        }, $contents, 1) ?: null;
    }

    private function upsertCssDeclaration(string $body, string $property, string $value): string
    {
        if (preg_match('/(^|\\s)'.preg_quote($property, '/').'\\s*:/i', $body) === 1) {
            return preg_replace(
                '/'.preg_quote($property, '/').'\\s*:\\s*[^;]+;/i',
                $property.': '.$value.';',
                $body,
                1,
            ) ?? $body;
        }

        $indent = str_contains($body, "\n") ? '  ' : ' ';

        return rtrim($body)."\n".$indent.$property.': '.$value.";\n";
    }

    private function singleFileUnifiedDiff(string $relativePath, string $original, string $updated): string
    {
        $oldPath = tempnam(sys_get_temp_dir(), 'atlas-dev-old-');
        $newPath = tempnam(sys_get_temp_dir(), 'atlas-dev-new-');
        if ($oldPath !== false && $newPath !== false) {
            try {
                file_put_contents($oldPath, $original);
                file_put_contents($newPath, $updated);

                $process = new Process([
                    'diff',
                    '-u',
                    '--label',
                    'a/'.$relativePath,
                    '--label',
                    'b/'.$relativePath,
                    $oldPath,
                    $newPath,
                ]);
                $process->run();
                $diff = $process->getOutput();
                if ($process->getExitCode() === 1 && $diff !== '') {
                    return str_ends_with($diff, "\n") ? $diff : $diff."\n";
                }
            } finally {
                @unlink($oldPath);
                @unlink($newPath);
            }
        }

        $oldLines = explode("\n", $original);
        $newLines = explode("\n", $updated);
        $oldHadTrailingNewline = str_ends_with($original, "\n");
        $newHadTrailingNewline = str_ends_with($updated, "\n");
        if ($oldHadTrailingNewline) {
            array_pop($oldLines);
        }
        if ($newHadTrailingNewline) {
            array_pop($newLines);
        }

        $diff = [
            '--- a/'.$relativePath,
            '+++ b/'.$relativePath,
            sprintf('@@ -1,%d +1,%d @@', max(1, count($oldLines)), max(1, count($newLines))),
        ];
        $max = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $max; $i++) {
            $old = $oldLines[$i] ?? null;
            $new = $newLines[$i] ?? null;
            if ($old !== null && $new !== null && $old === $new) {
                $diff[] = ' '.$old;

                continue;
            }
            if ($old !== null) {
                $diff[] = '-'.$old;
            }
            if ($new !== null) {
                $diff[] = '+'.$new;
            }
        }

        return implode("\n", $diff)."\n";
    }

    private function applyPatchIfSafe(
        DiffParseResult $diffResult,
        string $scopeStatus,
        string $workspace,
        ProviderCallResult $callResult,
    ): PatchApplyResult {
        if (! $diffResult->hasPatch()) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'no_patch',
            );
        }

        if ($this->providerMutatedWorkspace($callResult)) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_SKIPPED,
                exitCode: 0,
                durationMs: 0,
                stdout: '',
                stderr: '',
                reason: 'provider_mutated_workspace',
            );
        }

        if ($scopeStatus !== ScopeGuardReceipt::STATUS_PASSED) {
            return new PatchApplyResult(
                status: PatchApplyResult::STATUS_FAILED,
                exitCode: 1,
                durationMs: 0,
                stdout: '',
                stderr: 'Patch application skipped because scope guard did not pass.',
                reason: 'scope_guard_not_passed',
            );
        }

        return (new PatchApplier)->apply($diffResult, $workspace);
    }

    private function providerMutatedWorkspace(ProviderCallResult $callResult): bool
    {
        // Single source of truth shared with the prompt contract
        // (ProviderPromptBuilder::adaptSectionsForProvider) so the way Atlas reads
        // the result can never diverge from what the provider was told to do.
        // {@see WorkspaceMutatingProviders}
        return WorkspaceMutatingProviders::includes($callResult->actualProvider);
    }

    private function withProviderError(ProviderCallResult $result, string $error): ProviderCallResult
    {
        return new ProviderCallResult(
            runId: $result->runId,
            actualProvider: $result->actualProvider,
            actualModelFamily: $result->actualModelFamily,
            exitStatus: $result->exitStatus,
            stdout: $result->stdout,
            stderr: $result->stderr,
            durationMs: $result->durationMs,
            tokensIn: $result->tokensIn,
            tokensOut: $result->tokensOut,
            costEstimateUsd: $result->costEstimateUsd,
            rawResponseHash: $result->rawResponseHash,
            providerSafe: $result->providerSafe,
            errors: array_values(array_unique([...$result->errors, $error])),
        );
    }

    private function verificationFailedDueToPatchApply(PatchApplyResult $result): VerificationGateResult
    {
        return new VerificationGateResult(
            tests: [],
            gates: [
                new GateOutcome(
                    name: 'patch_apply_gate',
                    status: GateOutcome::STATUS_FAILED,
                    required: true,
                    evidenceRef: 'patch_apply_result',
                    fresh: true,
                    waiverReason: null,
                ),
            ],
            aggregateStatus: VerificationGateResult::STATUS_FAILED,
            honestyFlags: ['patch_apply_failed:'.($result->reason ?? 'unknown')],
            evidenceRefs: [],
            profile: 'patch_apply',
        );
    }

    private function verificationReceiptStorage(string $storageRunId): ReceiptStorageAdapter
    {
        $storage = $this->storage;

        return new class($storage, $storageRunId) implements ReceiptStorageAdapter
        {
            public function __construct(
                private readonly ReceiptStorage $storage,
                private readonly string $storageRunId,
            ) {}

            public function writeTestLog(string $runId, int $index, string $output): ?string
            {
                $base = sprintf('test_log_%02d', max(1, $index));

                return $this->storage->writeMonotonic($this->storageRunId, $base, [
                    'schema_version' => 'atlas.dev.verification_test_log.v1',
                    'run_id' => $this->storageRunId,
                    'source_run_id' => $runId,
                    'index' => max(1, $index),
                    'output_hash' => hash('sha256', $output),
                    'combined_output' => $output,
                    'recorded_at' => gmdate('c'),
                ])['path'];
            }
        };
    }

    private function resolve(string $abstract): ?object
    {
        if (! $this->container->bound($abstract)) {
            return null;
        }

        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    private function resolveConcrete(string $abstract): ?object
    {
        try {
            $resolved = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return is_object($resolved) ? $resolved : null;
    }

    private function blockedDueToUnwiredDrivers(
        OperationEnvelope $envelope,
        bool $gatewayMissing,
        bool $commandRunnerMissing,
        string $provider = 'claude_cli',
        string $modelFamily = 'sonnet',
    ): RunExecutionResult {
        $reasons = [];
        if ($gatewayMissing) {
            $reasons[] = 'claude_cli_gateway_unbound';
        }
        if ($commandRunnerMissing) {
            $reasons[] = 'verification_command_runner_unbound';
        }

        return new RunExecutionResult(
            completionState: 'blocked',
            scopeGuardStatus: 'skipped',
            verificationStatus: 'skipped',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => $provider,
                'model_family' => $modelFamily,
                'provider_calls' => 0,
                'exit_code' => 0,
                'duration_ms' => 0,
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => $reasons,
                'raw_response_hash' => null,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function enforceAucriBeforeProvider(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        string $riskLevel,
        string $taskKind,
    ): array {
        $segments = [
            [
                'kind' => 'decision',
                'ref' => 'atlas_dev:task_contract:'.$taskContract->taskContractHash,
                'tokens' => 700,
                'priority' => 1.0,
                'must_keep' => true,
                'content' => 'Atlas Dev task contract must govern provider execution.',
            ],
            [
                'kind' => 'constraint',
                'ref' => 'atlas_dev:scope:'.$envelope->workspaceHash,
                'tokens' => 650,
                'priority' => 0.98,
                'must_keep' => true,
                'content' => implode('|', [
                    'max_files_changed='.$taskContract->maxFilesChanged,
                    'allowed_files='.implode(',', $taskContract->allowedFiles),
                    'blocked_actions='.implode(',', $taskContract->blockedActions),
                ]),
            ],
            [
                'kind' => 'evidence',
                'ref' => 'atlas_dev:prompt_projection:'.$promptProjection->promptProjectionHash,
                'tokens' => max(500, min(6000, (int) ceil(strlen($promptProjection->renderedPromptText) / 4))),
                'priority' => 0.94,
                'must_keep' => true,
                'content' => $promptProjection->renderedPromptText,
            ],
        ];

        $enforcement = app(AtlasAucriRuntimeEnforcementService::class)->enforce([
            'flow_id' => 'atlas_dev',
            'domain' => 'programming',
            'task_type' => $taskKind,
            'risk_level' => $riskLevel,
            'provider' => $taskContract->providerLock->provider,
            'provider_target' => 'external',
            'objective' => $envelope->normalizedIntent,
            'rendered_prompt_text' => $promptProjection->renderedPromptText,
            'source_refs' => [
                ['ref' => 'operation_envelope:'.$envelope->envelopeHash],
                ['ref' => 'task_contract:'.$taskContract->taskContractHash],
                ['ref' => 'prompt_projection:'.$promptProjection->promptProjectionHash],
            ],
            'required_sources' => [
                'operation_envelope:'.$envelope->envelopeHash,
                'task_contract:'.$taskContract->taskContractHash,
                'prompt_projection:'.$promptProjection->promptProjectionHash,
            ],
            'segments' => $segments,
            'task' => 'execute_provider_patch',
        ]);

        $this->storage->writeAtomic($runId, ArtifactNames::AUCRI_RUNTIME_ENFORCEMENT, $enforcement);

        return $enforcement;
    }

    /**
     * @param  array<string,mixed>  $enforcement
     */
    private function blockedDueToAucri(array $enforcement): RunExecutionResult
    {
        return new RunExecutionResult(
            completionState: 'blocked',
            scopeGuardStatus: 'skipped',
            verificationStatus: 'skipped',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'provider_calls' => 0,
                'exit_code' => 0,
                'duration_ms' => 0,
                'tokens_in' => null,
                'tokens_out' => null,
                'estimated_cost_usd' => null,
                'error_codes' => array_values((array) ($enforcement['blockers'] ?? ['aucri_runtime_enforcement_blocked'])),
                'raw_response_hash' => null,
                'stdout_bytes' => 0,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: null,
        );
    }

    private function contextPackHash(string $runId): string
    {
        $projection = $this->storage->read($runId, ArtifactNames::OPEN_BRAIN_PROJECTION);
        if (is_array($projection) && isset($projection['context_pack_hash']) && is_string($projection['context_pack_hash']) && $projection['context_pack_hash'] !== '') {
            return $projection['context_pack_hash'];
        }

        return 'atlas-dev:context_pack:unknown';
    }

    private function providerTimeoutSeconds(?LightTaskContract $taskContract = null): int
    {
        if ($taskContract?->providerLock->provider === 'hermes_cli') {
            // Hermes is a heavy multi-turn executive runtime — give it the generous
            // hermes_cli ceiling (config default 600s) rather than the short Dev default.
            return max(1, (int) config('atlas.ai.providers.hermes_cli.timeout_seconds', 600));
        }

        if ($taskContract?->providerLock->provider === AtlasForgeCursorCliInvocationDriver::PROVIDER) {
            return max(1, (int) config('atlas.ai.providers.cursor_cli.timeout_seconds', 120));
        }

        if ($taskContract?->providerLock->provider === AtlasForgeMinimaxM27CliInvocationDriver::PROVIDER) {
            return max(1, (int) config('atlas.ai.providers.minimax_m27_cli.timeout_seconds', 300));
        }

        return max(1, (int) config('atlas_dev.provider.timeout_seconds', SonnetClaudeCliAdapter::DEFAULT_TIMEOUT_SECONDS));
    }

    private function providerMaxOutputChars(?LightTaskContract $taskContract = null): int
    {
        if ($taskContract?->providerLock->provider === AtlasForgeCursorCliInvocationDriver::PROVIDER) {
            return max(200, (int) config('atlas.ai.providers.cursor_cli.max_output_chars', 12000));
        }

        return 12000;
    }

    /**
     * F-03 fail-closed read of the persisted CompactSDD.
     *
     * The VerificationReceipt's task_kind / risk_level MUST mirror the
     * CompactSDD produced during planning. Surfaces never get to influence
     * them — surface_context.composer_task ("dev"/"debug"/"review") uses a
     * different vocabulary and would silently corrupt the receipt if used
     * as task_kind.
     *
     * If compact_sdd.json is missing, carries values outside the receipt's
     * allowed enums, or no longer matches the hash pinned by the persisted
     * MiniProgrammingSpec, we throw {@see CompactSddUnavailableException}. The
     * RunController maps that to HTTP 422 with a typed error code, and the
     * provider is never invoked — no wasted call on an unattestable run.
     *
     * @return array{0:string,1:string}
     */
    private function resolveTaskKindAndRiskLevel(string $runId, ?string $expectedCompactSddHash = null): array
    {
        $compactSdd = $this->storage->read($runId, ArtifactNames::COMPACT_SDD);
        if (! is_array($compactSdd)) {
            throw CompactSddUnavailableException::missing($runId);
        }

        $taskKind = $compactSdd['task_kind'] ?? null;
        if (! is_string($taskKind) || ! in_array($taskKind, VerificationReceipt::ALLOWED_TASK_KINDS, true)) {
            $observed = is_string($taskKind) ? $taskKind : gettype($taskKind);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "task_kind '{$observed}' is not in VerificationReceipt::ALLOWED_TASK_KINDS",
            );
        }

        $riskLevel = $compactSdd['risk_level'] ?? null;
        if (! is_string($riskLevel) || ! in_array($riskLevel, VerificationReceipt::ALLOWED_RISK_LEVELS, true)) {
            $observed = is_string($riskLevel) ? $riskLevel : gettype($riskLevel);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "risk_level '{$observed}' is not in VerificationReceipt::ALLOWED_RISK_LEVELS",
            );
        }

        $this->assertCompactSddHashPinned($runId, $compactSdd, $expectedCompactSddHash);

        return [$taskKind, $riskLevel];
    }

    /**
     * Three-layer integrity check on the on-disk CompactSDD:
     *
     *   1. **Self-hash**: the embedded `compact_sdd_hash` field must match the
     *      recomputed canonical hash of the same payload (catches tampering
     *      where the attacker forgot to recompute, OR didn't have the source
     *      data needed to recompute consistently).
     *   2. **Disk pin**: MiniProgrammingSpec was written by the orchestrator
     *      with the same `compact_sdd_hash`. The two on-disk artifacts must
     *      agree (catches single-file tampering).
     *   3. **Server-side pin**: when a confirmation_token row was issued at
     *      Plan time, it stored the canonical hash too. The token row is HMAC-
     *      keyed (only the server can forge it). The disk hash MUST match
     *      this server-side pin — this is what stops a coordinated rewrite
     *      of compact_sdd.json + mini_programming_spec.json.
     *
     * The third check is gated on `$expectedCompactSddHash` being provided.
     * The Run endpoint always passes it (sourced from
     * {@see ConfirmationTokenResult::$expectedCompactSddHash}); legacy / test
     * paths that bypass the controller can supply null and rely on the two
     * disk-only layers.
     *
     * @param  array<string, mixed>  $compactSdd
     */
    private function assertCompactSddHashPinned(
        string $runId,
        array $compactSdd,
        ?string $expectedCompactSddHash = null,
    ): void {
        $declaredHash = $compactSdd['compact_sdd_hash'] ?? null;
        if (! is_string($declaredHash) || $declaredHash === '') {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd_hash is missing');
        }

        try {
            $dto = CompactSdd::fromArray($compactSdd);
        } catch (\Throwable $e) {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd cannot be reconstructed: '.$e->getMessage());
        }

        $computedHash = $dto->hash();
        if (! hash_equals($declaredHash, $computedHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the current compact_sdd payload',
            );
        }

        // F-03 server-side pin: defeats the case where an attacker rewrites
        // BOTH compact_sdd.json AND mini_programming_spec.json with a fresh
        // hash. The expected hash here came from the HMAC-keyed token row,
        // which the attacker cannot forge without APP_KEY.
        if ($expectedCompactSddHash !== null && $expectedCompactSddHash !== '') {
            if (! hash_equals($expectedCompactSddHash, $declaredHash)) {
                throw CompactSddUnavailableException::tampered(
                    $runId,
                    'compact_sdd_hash does not match the server-side pin issued at plan time',
                );
            }
        }

        $miniSpec = $this->storage->read($runId, ArtifactNames::MINI_PROGRAMMING_SPEC);
        $pinnedHash = is_array($miniSpec) ? ($miniSpec['compact_sdd_hash'] ?? null) : null;
        if (! is_string($pinnedHash) || $pinnedHash === '') {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'mini_programming_spec compact_sdd_hash pin is missing',
            );
        }

        if (! hash_equals($pinnedHash, $declaredHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the hash pinned by mini_programming_spec',
            );
        }
    }
}
