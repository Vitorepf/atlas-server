<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;


/**
 * Atlas Forge Provider Invocation Failure Classifier.
 *
 * Maps a provider runtime result (exit_code + stdout + stderr + timed_out)
 * into a canonical `AtlasForgeProviderFallbackPolicyService` failure type
 * with confidence + fallback recommendation. NEVER calls an external
 * provider. The classifier is the single source of truth for "what kind
 * of failure was this, and what should the operator do next".
 *
 * Schema: atlas.forge.provider_invocation_failure_classification.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
 */
class AtlasForgeProviderInvocationFailureClassifier
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_invocation_failure_classification.v1';

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    /**
     * @param  array<string,mixed>  $signals  provider, model, exit_code, stdout, stderr, timed_out, duration_ms
     * @return array<string,mixed>
     */
    public function classify(array $signals): array
    {
        $provider = (string) ($signals['provider'] ?? 'unknown');
        $model = (string) ($signals['model'] ?? 'unknown');
        $exitCode = $signals['exit_code'] ?? null;
        $stdout = (string) ($signals['stdout'] ?? '');
        $stderr = (string) ($signals['stderr'] ?? '');
        $timedOut = (bool) ($signals['timed_out'] ?? false);
        $haystack = strtolower($stdout."\n".$stderr);

        $failureType = AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR;
        $confidence = self::CONFIDENCE_LOW;
        $reason = 'generic_provider_error';

        if ($timedOut) {
            return $this->build(
                provider: $provider,
                model: $model,
                exitCode: $exitCode,
                failureType: AtlasForgeProviderFallbackPolicyService::FAILURE_TIMEOUT,
                confidence: self::CONFIDENCE_HIGH,
                reason: 'process_timed_out',
            );
        }

        $patterns = [
            AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT => [
                'rate limit', 'too many requests', '429', 'slow down', 'throttle',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED => [
                'quota', 'credit', 'billing', 'usage limit', 'plan limit', 'subscription required',
                'plan_required', 'free users', 'upgrade to pro',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED => [
                'unauthorized', '401', 'auth', 'api key', 'login required', 'permission denied', 'invalid token',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_CONTEXT_LIMIT => [
                'context length', 'token limit', 'maximum context', 'too many tokens',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE => [
                'model not found', 'unknown model', 'model unavailable', 'unsupported model',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_INSUFFICIENT_CAPABILITY => [
                'capability not supported', 'unsupported feature', 'feature not available',
            ],
            AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED => [
                'no providers available', 'all providers exhausted', 'capacity exhausted',
            ],
        ];

        foreach ($patterns as $candidate => $tokens) {
            foreach ($tokens as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $failureType = $candidate;
                    $confidence = self::CONFIDENCE_HIGH;
                    $reason = 'matched:'.$needle;
                    break 2;
                }
            }
        }

        if ($failureType === AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR
            && is_int($exitCode) && $exitCode !== 0) {
            $confidence = self::CONFIDENCE_MEDIUM;
            $reason = 'non_zero_exit_code';
        }

        return $this->build(
            provider: $provider,
            model: $model,
            exitCode: $exitCode,
            failureType: $failureType,
            confidence: $confidence,
            reason: $reason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function build(string $provider, string $model, mixed $exitCode, string $failureType, string $confidence, string $reason): array
    {
        $shouldRecord = ! in_array($failureType, [
            // model_unavailable + insufficient_capability are diagnostic, not
            // operational provider failures — still record to inform Atlas
            // Decide on next dispatch.
        ], true);

        $fallbackRecommended = in_array($failureType, [
            AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED,
            AtlasForgeProviderFallbackPolicyService::FAILURE_CONTEXT_LIMIT,
            AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE,
            AtlasForgeProviderFallbackPolicyService::FAILURE_INSUFFICIENT_CAPABILITY,
        ], true);

        $retryLaterRecommended = in_array($failureType, [
            AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT,
            AtlasForgeProviderFallbackPolicyService::FAILURE_TIMEOUT,
            AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR,
        ], true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider' => $provider,
            'model' => $model,
            'exit_code' => is_int($exitCode) ? $exitCode : null,
            'failure_type' => $failureType,
            'confidence' => $confidence,
            'reason' => $reason,
            'should_record_failure_memory' => $shouldRecord,
            'fallback_recommended' => $fallbackRecommended,
            'retry_later_recommended' => $retryLaterRecommended,
            'note' => 'Classificacao deterministica baseada em stdout/stderr/exit_code/timeout; sem chamada provider externa.',
        ];
    }
}
