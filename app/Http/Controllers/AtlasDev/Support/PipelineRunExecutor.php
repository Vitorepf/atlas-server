<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Gate\CompletionStateGate;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptComposer;
use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Provider\ClaudeCliGateway;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;

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
    ): RunExecutionResult {
        // F-03: derive task_kind / risk_level from the persisted CompactSDD
        // BEFORE the provider is invoked. If it is missing or invalid we
        // fail closed (CompactSddUnavailableException → 422) instead of
        // wasting a provider call on a run we cannot honestly attest.
        [$taskKind, $riskLevel] = $this->resolveTaskKindAndRiskLevel($runId);

        $gateway = $this->resolve(ClaudeCliGateway::class);
        $commandRunner = $this->resolve(VerificationCommandRunner::class);

        if ($gateway === null || $commandRunner === null) {
            return $this->blockedDueToUnwiredDrivers($envelope, $gateway === null, $commandRunner === null);
        }

        $adapter = new SonnetClaudeCliAdapter($gateway);
        $callResult = $adapter->executeOneCall(
            promptProjection: $promptProjection,
            taskContract: $taskContract,
            workspace: $envelope->workspace,
        );

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
        );
        $callResultForGates = $patchApplyResult->ok()
            ? $callResult
            : $this->withProviderError($callResult, 'patch_apply_failed');

        $verificationResult = $patchApplyResult->ok()
            ? (new VerificationGate($commandRunner))->run(
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

        return new RunExecutionResult(
            completionState: $receipt->completion->status,
            scopeGuardStatus: $scopeReceipt->status,
            verificationStatus: $verificationResult->aggregateStatus,
            persistedReceiptPaths: $persisted,
            providerCallSummary: [
                'provider' => $callResult->actualProvider,
                'model_family' => $callResult->actualModelFamily,
                'provider_calls' => 1,
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

    private function applyPatchIfSafe(
        DiffParseResult $diffResult,
        string $scopeStatus,
        string $workspace,
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

    private function blockedDueToUnwiredDrivers(
        OperationEnvelope $envelope,
        bool $gatewayMissing,
        bool $commandRunnerMissing,
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
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
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

    private function contextPackHash(string $runId): string
    {
        $projection = $this->storage->read($runId, ArtifactNames::OPEN_BRAIN_PROJECTION);
        if (is_array($projection) && isset($projection['context_pack_hash']) && is_string($projection['context_pack_hash']) && $projection['context_pack_hash'] !== '') {
            return $projection['context_pack_hash'];
        }

        return 'atlas-dev:context_pack:unknown';
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
     * If compact_sdd.json is missing or carries values outside the receipt's
     * allowed enums, we throw {@see CompactSddUnavailableException}. The
     * RunController maps that to HTTP 422 with a typed error code, and the
     * provider is never invoked — no wasted call on an unattestable run.
     *
     * @return array{0:string,1:string}
     */
    private function resolveTaskKindAndRiskLevel(string $runId): array
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

        return [$taskKind, $riskLevel];
    }
}
