<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support\PipelineRun;

use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Gate\ReceiptStorageAdapter;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Provider\SonnetClaudeCliAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\WorkspaceMutatingProviders;
use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeMinimaxM27CliInvocationDriver;
use App\Support\UtcIsoTimestamp;

/**
 * Provider call result composition + provider budget limits.
 *
 * Extracted verbatim from PipelineRunExecutor (godfile split, GOD-DEBULK
 * 2026-07-22). Behavior unchanged; cross-family calls route through the
 * sibling sections injected below.
 */
final class ProviderResultSupport
{
    public function __construct(
        private readonly ReceiptStorage $storage,
    ) {}

    public function blockedProviderCallResult(
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

    public function providerMutatedWorkspace(ProviderCallResult $callResult): bool
    {
        // Single source of truth shared with the prompt contract
        // (ProviderPromptBuilder::adaptSectionsForProvider) so the way Atlas reads
        // the result can never diverge from what the provider was told to do.
        // {@see WorkspaceMutatingProviders}
        return WorkspaceMutatingProviders::includes($callResult->actualProvider);
    }

    public function withProviderError(ProviderCallResult $result, string $error): ProviderCallResult
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

    public function verificationFailedDueToPatchApply(PatchApplyResult $result): VerificationGateResult
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

    public function verificationReceiptStorage(string $storageRunId): ReceiptStorageAdapter
    {
        $storage = $this->storage;

        return new class($storage, $storageRunId) implements ReceiptStorageAdapter
        {
            public function __construct(
                private readonly ReceiptStorage $storage,
                private readonly string $storageRunId,
            ) {}

            public function writeTestLog(string $runId, int $index, string $output): string
            {
                $base = sprintf('test_log_%02d', max(1, $index));

                return $this->storage->writeMonotonic($this->storageRunId, $base, [
                    'schema_version' => 'atlas.dev.verification_test_log.v1',
                    'run_id' => $this->storageRunId,
                    'source_run_id' => $runId,
                    'index' => max(1, $index),
                    'output_hash' => hash('sha256', $output),
                    'combined_output' => $output,
                    'recorded_at' => UtcIsoTimestamp::now(),
                ])['path'];
            }
        };
    }

    public function providerTimeoutSeconds(?LightTaskContract $taskContract = null): int
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

    public function providerMaxOutputChars(?LightTaskContract $taskContract = null): int
    {
        if ($taskContract?->providerLock->provider === AtlasForgeCursorCliInvocationDriver::PROVIDER) {
            return max(200, (int) config('atlas.ai.providers.cursor_cli.max_output_chars', 12000));
        }

        return 12000;
    }
}
