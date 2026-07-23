<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\Ai\Policy\AiRuntimeBudgetService;

/**
 * Fair Claude mode + provider fallback budget gating family extracted VERBATIM
 * from AiWorker (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor
 * scanner pins stay on the facade. No scanner pin token moved with this family.
 */
class ProviderFairnessBudgetSection
{
    public function __construct(
        private readonly FairClaudePolicy $fairClaude,
        private readonly AiProviderModelResolver $models,
        private readonly AiRuntimeBudgetService $budgets,
        private readonly AiWorkerLogger $logger,
    ) {}

    public function fairModeRuntimeViolation(AiJob $job, string $providerKey, mixed $model, bool $requireModel = true): ?array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        if (! $this->fairClaude->isFairPayload($payload) && ! $this->fairClaude->isFairPayload($metadata)) {
            return null;
        }

        if (! $requireModel && $providerKey !== FairClaudePolicy::PROVIDER_LOCK) {
            return $this->fairClaude->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $providerKey],
            );
        }

        if (! $requireModel) {
            return null;
        }

        $mergedPayload = array_merge($payload, [
            'fair_mode' => data_get($payload, 'fair_mode') ?: data_get($metadata, 'fair_mode'),
            'dev_execution_plan' => data_get($payload, 'dev_execution_plan') ?: data_get($metadata, 'dev_execution_plan'),
        ]);
        $model = is_string($model) || is_numeric($model) ? trim((string) $model) : null;
        $violation = $this->fairClaude->validateInvocation($providerKey, $model !== '' ? $model : null, $mergedPayload);

        return (bool) ($violation['ok'] ?? false) ? null : $violation;
    }

    /**
     * @param  array<string,mixed>  $violation
     */
    public function fairModeViolationResult(array $violation): AiProviderResult
    {
        $message = (string) ($violation['message'] ?? 'Fair Claude mode violation.');

        return new AiProviderResult(
            ok: false,
            output: '',
            command: [],
            exitCode: null,
            durationMs: 0,
            stdout: '',
            stderr: $message,
            errorCode: FairClaudePolicy::ERROR_CODE,
            errorMessage: $message,
            metadata: [
                'fair_mode_violation' => $violation,
            ],
        );
    }

    public function fallbackProviderWithinBudget(AiJob $job, string $provider): bool
    {
        $model = $this->models->resolve($provider, null);

        try {
            $this->budgets->assertAllows($provider, $model, [
                'payload' => is_array($job->payload) ? $job->payload : [],
            ]);

            return true;
        } catch (\RuntimeException $exception) {
            $metadata = array_merge($job->metadata ?? [], [
                'fallback_budget_blocked' => true,
                'fallback_budget_provider' => $provider,
                'fallback_budget_model' => $model,
                'fallback_budget_error' => $exception->getMessage(),
                'fallback_budget_checked_at' => now()->toIso8601String(),
            ]);
            $job->forceFill(['metadata' => $metadata])->save();
            $job->trace?->forceFill([
                'metadata' => array_merge($job->trace->metadata ?? [], [
                    'fallback_budget_blocked' => true,
                    'fallback_budget_provider' => $provider,
                    'fallback_budget_model' => $model,
                    'fallback_budget_error' => $exception->getMessage(),
                ]),
            ])->save();

            $this->logger->event(
                eventType: 'provider_fallback_budget_blocked',
                message: 'Gemini fallback to Claude blocked by runtime budget.',
                severity: 'warning',
                provider: $job->provider,
                job: $job,
                metadata: [
                    'fallback_provider' => $provider,
                    'fallback_model' => $model,
                    'budget_error' => $exception->getMessage(),
                ],
            );

            return false;
        }
    }
}
