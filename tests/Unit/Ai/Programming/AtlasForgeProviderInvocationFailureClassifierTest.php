<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use PHPUnit\Framework\TestCase;

class AtlasForgeProviderInvocationFailureClassifierTest extends TestCase
{
    private AtlasForgeProviderInvocationFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AtlasForgeProviderInvocationFailureClassifier();
    }

    public function testClassifyReturnsSchemaVersion(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertArrayHasKey('schema_version', $result);
        $this->assertSame('atlas.forge.provider_invocation_failure_classification.v1', $result['schema_version']);
    }

    public function testClassifyPreservesProviderAndModel(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3-5-sonnet',
            'exit_code' => 1,
            'stdout' => 'error occurred',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame('claude-3-5-sonnet', $result['model']);
    }

    public function testClassifyTimeoutReturnsTimeoutFailure(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'timed_out' => true,
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_TIMEOUT, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertSame('process_timed_out', $result['reason']);
        $this->assertNull($result['exit_code']);
    }

    public function testClassifyRateLimitFromStdout(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 429,
            'stdout' => 'rate limit exceeded, please slow down',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
        $this->assertStringContainsString('matched:', $result['reason']);
    }

    public function testClassifyRateLimitFromStderr(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => 'Error 429: too many requests',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
    }

    public function testClassifyQuotaExhausted(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'quota exceeded for this month',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyBillingError(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'billing limit reached, upgrade to pro',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_QUOTA_EXHAUSTED, $result['failure_type']);
    }

    public function testClassifyAuthFailedUnauthorized(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 401,
            'stdout' => '',
            'stderr' => 'unauthorized access',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyAuthFailedInvalidApiKey(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => 'invalid api key provided',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_AUTH_FAILED, $result['failure_type']);
    }

    public function testClassifyContextLimitExceeded(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => 'context length exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_CONTEXT_LIMIT, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyTokenLimitReached(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'maximum token limit reached',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_CONTEXT_LIMIT, $result['failure_type']);
    }

    public function testClassifyModelUnavailable(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => 'model not found',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyUnknownModel(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-5',
            'exit_code' => 1,
            'stdout' => 'unknown model specified',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_MODEL_UNAVAILABLE, $result['failure_type']);
    }

    public function testClassifyInsufficientCapability(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'capability not supported by this model',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_INSUFFICIENT_CAPABILITY, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyFeatureNotAvailable(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'unsupported feature',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_INSUFFICIENT_CAPABILITY, $result['failure_type']);
    }

    public function testClassifyCapacityExhausted(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'no providers available at this time',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_HIGH, $result['confidence']);
    }

    public function testClassifyAllProvidersExhausted(): void
    {
        $signals = [
            'provider' => 'anthropic',
            'model' => 'claude-3',
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'all providers exhausted',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED, $result['failure_type']);
    }

    public function testClassifyNonZeroExitCodeDefaultsToProviderErrorMediumConfidence(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'command not found: some_binary',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_MEDIUM, $result['confidence']);
        $this->assertSame('non_zero_exit_code', $result['reason']);
        $this->assertSame(127, $result['exit_code']);
    }

    public function testClassifyZeroExitCodeWithNoSignalsReturnsProviderErrorLowConfidence(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => '',
            'timed_out' => false,
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR, $result['failure_type']);
        $this->assertSame(AtlasForgeProviderInvocationFailureClassifier::CONFIDENCE_LOW, $result['confidence']);
        $this->assertSame('generic_provider_error', $result['reason']);
    }

    public function testClassifyFallbackRecommendedForRateLimit(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'rate limit exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['fallback_recommended']);
    }

    public function testClassifyFallbackRecommendedForQuotaExhausted(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'quota exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['fallback_recommended']);
    }

    public function testClassifyFallbackRecommendedForContextLimit(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'context length exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['fallback_recommended']);
    }

    public function testClassifyFallbackRecommendedForModelUnavailable(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'model not found',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['fallback_recommended']);
    }

    public function testClassifyFallbackRecommendedForInsufficientCapability(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'unsupported feature',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['fallback_recommended']);
    }

    public function testClassifyFallbackNotRecommendedForAuthFailed(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 401,
            'stdout' => 'unauthorized',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['fallback_recommended']);
    }

    public function testClassifyFallbackNotRecommendedForCapacityExhausted(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'no providers available',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['fallback_recommended']);
    }

    public function testClassifyRetryLaterRecommendedForRateLimit(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'rate limit',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterRecommendedForTimeout(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'timed_out' => true,
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterRecommendedForProviderError(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'command not found',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterNotRecommendedForAuthFailed(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 401,
            'stdout' => 'unauthorized',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterNotRecommendedForQuotaExhausted(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'quota exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterNotRecommendedForContextLimit(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'context length exceeded',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['retry_later_recommended']);
    }

    public function testClassifyRetryLaterNotRecommendedForModelUnavailable(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'model not found',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['retry_later_recommended']);
    }

    public function testClassifyShouldRecordFailureForProviderError(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'command not found',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['should_record_failure_memory']);
    }

    public function testClassifyShouldRecordFailureForAuthFailed(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 401,
            'stdout' => 'unauthorized',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertTrue($result['should_record_failure_memory']);
    }

    public function testClassifyHandlesEmptySignalsGracefully(): void
    {
        $signals = [];

        $result = $this->classifier->classify($signals);

        $this->assertSame('unknown', $result['provider']);
        $this->assertSame('unknown', $result['model']);
        $this->assertNull($result['exit_code']);
        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_PROVIDER_ERROR, $result['failure_type']);
    }

    public function testClassifySearchesBothStdoutAndStderr(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'normal output here',
            'stderr' => 'rate limit detected in stderr',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
    }

    public function testClassifyCaseInsensitiveMatching(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'RATE LIMIT EXCEEDED',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
    }

    public function testClassifyExitCodeIsPreservedWhenInteger(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 42,
            'stdout' => '',
            'stderr' => 'some error',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(42, $result['exit_code']);
    }

    public function testClassifyExitCodeNullWhenNotInteger(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 'not_an_int',
            'stdout' => '',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertNull($result['exit_code']);
    }

    public function testClassifyReturnsNoteField(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertArrayHasKey('note', $result);
        $this->assertIsString($result['note']);
    }

    public function testFirstMatchingPatternWins(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'rate limit and unauthorized combined',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertSame(AtlasForgeProviderFallbackPolicyService::FAILURE_RATE_LIMIT, $result['failure_type']);
    }

    public function testClassifyCapacityExhaustedDoesNotRecommendFallback(): void
    {
        $signals = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'exit_code' => 1,
            'stdout' => 'capacity exhausted',
            'stderr' => '',
        ];

        $result = $this->classifier->classify($signals);

        $this->assertFalse($result['fallback_recommended']);
        $this->assertFalse($result['retry_later_recommended']);
    }
}