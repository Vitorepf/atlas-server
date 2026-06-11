<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Support;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosValueNormalizerTest extends TestCase
{
    public function test_string_or_null_trims_strings_and_rejects_non_strings(): void
    {
        $this->assertSame('alpha', AtlasAaeosValueNormalizer::stringOrNull(' alpha '));
        $this->assertNull(AtlasAaeosValueNormalizer::stringOrNull('   '));
        $this->assertNull(AtlasAaeosValueNormalizer::stringOrNull(42));
        $this->assertNull(AtlasAaeosValueNormalizer::stringOrNull(null));
    }

    public function test_risk_code_r0_to_r5_uses_configurable_fallback(): void
    {
        $this->assertSame('R4', AtlasAaeosValueNormalizer::riskCodeR0ToR5(' r4 ', 'R1'));
        $this->assertSame('R1', AtlasAaeosValueNormalizer::riskCodeR0ToR5('unknown', 'R1'));
        $this->assertSame('R0', AtlasAaeosValueNormalizer::riskCodeR0ToR5(null, 'R0'));
    }

    public function test_low_medium_high_risk_uses_configurable_fallback(): void
    {
        $this->assertSame('high', AtlasAaeosValueNormalizer::lowMediumHighRisk(' HIGH '));
        $this->assertSame('medium', AtlasAaeosValueNormalizer::lowMediumHighRisk('unknown'));
        $this->assertSame('low', AtlasAaeosValueNormalizer::lowMediumHighRisk('unknown', 'low'));
    }

    public function test_lowercase_allowed_preserves_allowed_set_and_fallback_policy(): void
    {
        $this->assertSame(
            'critical',
            AtlasAaeosValueNormalizer::lowercaseAllowed(' CRITICAL ', ['low', 'medium', 'high', 'critical'], 'low'),
        );
        $this->assertSame(
            'medium',
            AtlasAaeosValueNormalizer::lowercaseAllowed('unknown', ['none', 'low', 'medium', 'high'], 'medium'),
        );
        $this->assertSame(
            'high',
            AtlasAaeosValueNormalizer::lowercaseAllowed(null, ['low', 'medium', 'high'], 'high'),
        );
    }

    public function test_trimmed_allowed_preserves_case_sensitive_allowed_set(): void
    {
        $this->assertSame(
            'claimed',
            AtlasAaeosValueNormalizer::trimmedAllowed(' claimed ', ['available', 'claimed'], 'available'),
        );
        $this->assertSame(
            'available',
            AtlasAaeosValueNormalizer::trimmedAllowed(' CLAIMED ', ['available', 'claimed'], 'available'),
        );
        $this->assertSame(
            'available',
            AtlasAaeosValueNormalizer::trimmedAllowed(null, ['available', 'claimed'], 'available'),
        );
    }
}
