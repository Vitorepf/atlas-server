<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\ProviderFallbackHonestyClassifier;
use PHPUnit\Framework\TestCase;

final class ProviderFallbackHonestyClassifierTest extends TestCase
{
    private ProviderFallbackHonestyClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ProviderFallbackHonestyClassifier();
    }

    public function testConfiguredCompatibleProviderChoosesSemanticProvider(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => true,
                'invoked' => true,
                'succeeded' => true,
                'allowed_privacy_classes' => ['internal', 'public'],
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertSame('atlas.provider.fallback_honesty.v1', $result['schema_version']);
        $this->assertSame('semantic_provider', $result['mode']);
        $this->assertTrue($result['provider_invoked']);
        $this->assertTrue($result['fallback_honest']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('configured_compatible_provider_served_semantic_result', $result['audit_reason']);
    }

    public function testUnavailableProviderWithDeclaredFallbackChoosesExplicitLocalFallback(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => false,
                'compatible' => true,
                'invoked' => false,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertSame('explicit_local_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertTrue($result['fallback_honest']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('declared_local_fallback_for_unavailable_provider', $result['audit_reason']);
    }

    public function testSilentUndisclosedFallbackBlocks(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => false,
                'compatible' => true,
                'invoked' => false,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => false,
                'silent' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertSame('blocked_silent_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertFalse($result['fallback_honest']);
        $this->assertSame(['silent_fallback_not_disclosed'], $result['blockers']);
        $this->assertSame('fallback_present_but_silent_and_undisclosed', $result['audit_reason']);
    }

    public function testPrivacyClassMismatchBlocks(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => true,
                'invoked' => true,
                'succeeded' => true,
                'allowed_privacy_classes' => ['public'],
            ],
            [
                'privacy_class' => 'secret',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertSame('blocked_silent_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertFalse($result['fallback_honest']);
        $this->assertSame(['privacy_class_mismatch'], $result['blockers']);
        $this->assertSame('provider_blocked_on_privacy_class_mismatch', $result['audit_reason']);
    }

    public function testTimeoutIsTransientFailureNotSemanticSuccess(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => true,
                'invoked' => true,
                'timed_out' => true,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertNotSame('semantic_provider', $result['mode']);
        $this->assertSame('explicit_local_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertTrue($result['fallback_honest']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('declared_fallback_after_transient_timeout', $result['audit_reason']);
    }

    public function testRateLimitIsTransientFailureNotSemanticSuccess(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => true,
                'invoked' => true,
                'failure_reason' => 'rate_limit',
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertNotSame('semantic_provider', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertSame('explicit_local_fallback', $result['mode']);
        $this->assertSame('declared_fallback_after_transient_rate_limit', $result['audit_reason']);
    }

    public function testTransientFailureWithoutDeclaredFallbackBlocks(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => true,
                'invoked' => true,
                'rate_limited' => true,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => false,
            ],
        );

        $this->assertSame('blocked_silent_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertFalse($result['fallback_honest']);
        $this->assertSame(['provider_rate_limit_without_declared_fallback'], $result['blockers']);
        $this->assertSame('transient_rate_limit_has_no_declared_fallback', $result['audit_reason']);
    }

    public function testUnavailableProviderWithoutAnyFallbackBlocks(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => false,
                'compatible' => true,
                'invoked' => false,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => false,
            ],
        );

        $this->assertSame('blocked_silent_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertFalse($result['fallback_honest']);
        $this->assertSame(['provider_unavailable_without_declared_fallback'], $result['blockers']);
        $this->assertSame('no_declared_fallback_for_unavailable_provider', $result['audit_reason']);
    }

    public function testIncompatibleAvailableProviderDoesNotCountAsSemanticSuccess(): void
    {
        $result = $this->classifier->classify(
            [
                'available' => true,
                'compatible' => false,
                'invoked' => true,
                'succeeded' => true,
            ],
            [
                'privacy_class' => 'internal',
            ],
            [
                'declared' => true,
                'disclosed' => true,
                'target' => 'local_embedding',
            ],
        );

        $this->assertSame('explicit_local_fallback', $result['mode']);
        $this->assertFalse($result['provider_invoked']);
        $this->assertTrue($result['fallback_honest']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $providerState = [
            'available' => false,
            'compatible' => true,
            'invoked' => false,
        ];
        $request = [
            'privacy_class' => 'internal',
        ];
        $fallback = [
            'declared' => true,
            'disclosed' => true,
            'target' => 'local_embedding',
        ];

        $first = $this->classifier->classify($providerState, $request, $fallback);
        $second = $this->classifier->classify($providerState, $request, $fallback);

        $this->assertSame($first, $second);
    }
}
