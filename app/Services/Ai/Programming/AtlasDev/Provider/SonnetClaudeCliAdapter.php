<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use InvalidArgumentException;

/**
 * Locked Sonnet/claude_cli adapter for the Atlas Dev fast path.
 *
 * Invariants:
 *   - input is ALWAYS a typed ProviderPromptProjection. A raw string prompt
 *     is rejected at the signature level (no method takes `string $prompt`).
 *   - rendered_prompt_text must be non-empty and the projection must be
 *     `isSendable()`. Builder + quality checks already enforce this — this
 *     adapter re-asserts because it sits on the only path to the network.
 *   - provider/model are locked to claude_cli/sonnet with
 *     fallback_allowed=false; any gateway response reporting a different
 *     provider or model raises ProviderLockViolationException.
 *   - errors are surfaced literally in ProviderCallResult.errors — never
 *     swallowed, never auto-retried.
 */
final class SonnetClaudeCliAdapter
{
    public const PROVIDER = 'claude_cli';
    public const MODEL_FAMILY = 'sonnet';
    public const DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly ClaudeCliGateway $gateway,
    ) {}

    public function executeOneCall(
        ProviderPromptProjection $promptProjection,
        LightTaskContract $taskContract,
        string $workspace,
        ?int $timeoutSeconds = null,
    ): ProviderCallResult {
        $this->assertPromptIsSendable($promptProjection);
        $this->assertProviderLockMatches($taskContract);
        $this->assertWorkspace($workspace);

        $effectiveTimeout = $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS;
        if ($effectiveTimeout <= 0) {
            throw new InvalidArgumentException('SonnetClaudeCliAdapter.timeout_seconds must be positive.');
        }

        $request = new ClaudeCliRequest(
            runId: $promptProjection->runId,
            workspace: $workspace,
            provider: self::PROVIDER,
            modelFamily: self::MODEL_FAMILY,
            promptProjection: $promptProjection,
            timeoutSeconds: $effectiveTimeout,
            fallbackAllowed: false,
        );

        $response = $this->gateway->dispatch($request);

        $errors = [];

        if ($response->actualProvider !== self::PROVIDER) {
            throw new ProviderLockViolationException(
                'SonnetClaudeCliAdapter: gateway returned provider='
                .$response->actualProvider.', expected '.self::PROVIDER
                .' (fallback_allowed=false).'
            );
        }

        if ($response->actualModelFamily !== self::MODEL_FAMILY) {
            throw new ProviderLockViolationException(
                'SonnetClaudeCliAdapter: gateway returned model_family='
                .$response->actualModelFamily.', expected '.self::MODEL_FAMILY
                .' (fallback_allowed=false).'
            );
        }

        if ($response->exitCode !== 0) {
            $errors[] = 'provider_exit_'.$response->exitCode;
        }

        if (trim($response->stdout) === '' && $response->exitCode === 0) {
            $errors[] = 'empty_stdout_with_zero_exit';
        }

        return ProviderCallResult::fromStdout(
            runId: $promptProjection->runId,
            actualProvider: $response->actualProvider,
            actualModelFamily: $response->actualModelFamily,
            exitStatus: $response->exitCode,
            stdout: $response->stdout,
            stderr: $response->stderr,
            durationMs: $response->durationMs,
            tokensIn: $response->tokensIn,
            tokensOut: $response->tokensOut,
            costEstimateUsd: $response->costEstimateUsd,
            providerSafe: true,
            errors: $errors,
        );
    }

    private function assertPromptIsSendable(ProviderPromptProjection $projection): void
    {
        if ($projection->renderedPromptText === '') {
            throw new InvalidArgumentException(
                'SonnetClaudeCliAdapter: rendered_prompt_text is empty; refuses to call provider.'
            );
        }
        if (! $projection->isSendable()) {
            throw new InvalidArgumentException(
                'SonnetClaudeCliAdapter: prompt projection failed quality_checks: '
                .implode(',', $projection->qualityChecks->failedChecks())
            );
        }
    }

    private function assertProviderLockMatches(LightTaskContract $taskContract): void
    {
        $lock = $taskContract->providerLock;

        if ($lock->provider !== self::PROVIDER) {
            throw new ProviderLockViolationException(
                'SonnetClaudeCliAdapter: task_contract.provider_lock.provider='
                .$lock->provider.', adapter is locked to '.self::PROVIDER.'.'
            );
        }
        if ($lock->modelFamily !== self::MODEL_FAMILY) {
            throw new ProviderLockViolationException(
                'SonnetClaudeCliAdapter: task_contract.provider_lock.model_family='
                .$lock->modelFamily.', adapter is locked to '.self::MODEL_FAMILY.'.'
            );
        }
        if ($lock->fallbackAllowed) {
            throw new ProviderLockViolationException(
                'SonnetClaudeCliAdapter: task_contract.provider_lock.fallback_allowed=true is forbidden on the fast path.'
            );
        }
    }

    private function assertWorkspace(string $workspace): void
    {
        if (trim($workspace) === '') {
            throw new InvalidArgumentException('SonnetClaudeCliAdapter.workspace must not be empty.');
        }
    }
}
