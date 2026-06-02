<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context;

use App\Services\Ai\Context\LocalPrereasoningEligibilityClassifier;
use PHPUnit\Framework\TestCase;

final class LocalPrereasoningEligibilityClassifierTest extends TestCase
{
    private LocalPrereasoningEligibilityClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new LocalPrereasoningEligibilityClassifier();
    }

    public function testCountTaskResolvesLocallyAndAvoidsProviderWithSavedTokens(): void
    {
        $result = $this->classifier->classify('count', 1200);

        $this->assertTrue($result['can_resolve_locally']);
        $this->assertTrue($result['provider_call_avoidable']);
        $this->assertGreaterThanOrEqual(500, $result['saved_tokens_estimate']);
        $this->assertSame(1200, $result['saved_tokens_estimate']);
        $this->assertSame('local_prereasoning_can_resolve', $result['reason']);
    }

    public function testRefactorTaskRequiresProviderWithZeroSavedTokens(): void
    {
        $result = $this->classifier->classify('refactor', 5000);

        $this->assertFalse($result['can_resolve_locally']);
        $this->assertFalse($result['provider_call_avoidable']);
        $this->assertSame(0, $result['saved_tokens_estimate']);
        $this->assertSame('requires_provider_reasoning', $result['reason']);
    }

    public function testDiffTaskBelowFloorIsClampedToFiveHundred(): void
    {
        $result = $this->classifier->classify('diff', 200);

        $this->assertTrue($result['can_resolve_locally']);
        $this->assertSame(500, $result['saved_tokens_estimate']);
    }

    public function testEmptyTaskTypeIsNotAvoidableAndRequiresProvider(): void
    {
        $result = $this->classifier->classify('', 900);

        $this->assertFalse($result['provider_call_avoidable']);
        $this->assertFalse($result['can_resolve_locally']);
        $this->assertSame(0, $result['saved_tokens_estimate']);
        $this->assertSame('requires_provider_reasoning', $result['reason']);
    }

    public function testUnknownTaskTypeIsNotAvoidableAndRequiresProvider(): void
    {
        $result = $this->classifier->classify('unknown', 900);

        $this->assertFalse($result['provider_call_avoidable']);
        $this->assertFalse($result['can_resolve_locally']);
        $this->assertSame(0, $result['saved_tokens_estimate']);
        $this->assertSame('requires_provider_reasoning', $result['reason']);
    }

    public function testAllowedOperationsAreStableOnResolvableBranch(): void
    {
        $result = $this->classifier->classify('validate', 700);

        $this->assertSame(['diff', 'count', 'parse', 'validate', 'hash'], $result['allowed_operations']);
    }

    public function testAllowedOperationsAreStableOnNonResolvableBranch(): void
    {
        $result = $this->classifier->classify('summarize', 700);

        $this->assertSame(['diff', 'count', 'parse', 'validate', 'hash'], $result['allowed_operations']);
    }

    public function testWhitespaceTaskTypeNormalizesToGeneralAndIsNotResolvable(): void
    {
        $result = $this->classifier->classify('   ', 1500);

        $this->assertFalse($result['can_resolve_locally']);
        $this->assertFalse($result['provider_call_avoidable']);
        $this->assertSame(0, $result['saved_tokens_estimate']);
        $this->assertSame('requires_provider_reasoning', $result['reason']);
    }

    public function testTaskTypeIsCaseInsensitiveAndAboveFloorPassesThrough(): void
    {
        $result = $this->classifier->classify('PARSE', 3200);

        $this->assertTrue($result['can_resolve_locally']);
        $this->assertTrue($result['provider_call_avoidable']);
        $this->assertSame(3200, $result['saved_tokens_estimate']);
        $this->assertSame('local_prereasoning_can_resolve', $result['reason']);
    }

    public function testClassifyTaskTypeItselfResolvesLocally(): void
    {
        $result = $this->classifier->classify('classify', 480);

        $this->assertTrue($result['can_resolve_locally']);
        $this->assertSame(500, $result['saved_tokens_estimate']);
    }

    public function testWhitespacePaddedKeywordIsNotResolvableFailClosed(): void
    {
        // The canonical source lowercases without trimming, so a padded keyword
        // must NOT slip through as locally resolvable (that would wrongly avoid a
        // provider call on malformed input). Probes the fail-closed boundary that
        // a bare-keyword test never exercises.
        $result = $this->classifier->classify('  count  ', 1200);

        $this->assertFalse($result['can_resolve_locally']);
        $this->assertFalse($result['provider_call_avoidable']);
        $this->assertSame(0, $result['saved_tokens_estimate']);
        $this->assertSame('requires_provider_reasoning', $result['reason']);
    }

    public function testSchemaVersionLiteralIsStable(): void
    {
        $result = $this->classifier->classify('count', 600);

        $this->assertSame('atlas.token_economy.local_prereasoning_eligibility.v1', $result['schema_version']);
    }
}
