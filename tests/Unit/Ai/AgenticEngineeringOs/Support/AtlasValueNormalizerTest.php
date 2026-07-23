<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasAeosValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasAeosValueNormalizerTest extends TestCase
{
    public function test_string_or_null_trims_strings_and_rejects_non_strings(): void
    {
        $this->assertSame('alpha', AtlasAeosValueNormalizer::stringOrNull(' alpha '));
        $this->assertNull(AtlasAeosValueNormalizer::stringOrNull('   '));
        $this->assertNull(AtlasAeosValueNormalizer::stringOrNull(42));
        $this->assertNull(AtlasAeosValueNormalizer::stringOrNull(null));
    }

    public function test_lower_string_or_null_trims_lowercases_and_rejects_non_strings(): void
    {
        $this->assertSame('desktop', AtlasAeosValueNormalizer::lowerStringOrNull(' Desktop '));
        $this->assertNull(AtlasAeosValueNormalizer::lowerStringOrNull('   '));
        $this->assertNull(AtlasAeosValueNormalizer::lowerStringOrNull(42));
    }

    public function test_trimmed_lower_and_non_blank_helpers_preserve_aaeos_mixed_value_semantics(): void
    {
        $this->assertSame('Alpha', AtlasAeosValueNormalizer::trimmedString(' Alpha '));
        $this->assertSame('', AtlasAeosValueNormalizer::trimmedString(42));
        $this->assertSame('alpha', AtlasAeosValueNormalizer::lowerString(' Alpha '));
        $this->assertTrue(AtlasAeosValueNormalizer::isNonBlankString(' alpha '));
        $this->assertFalse(AtlasAeosValueNormalizer::isNonBlankString('   '));
        $this->assertFalse(AtlasAeosValueNormalizer::isNonBlankString(42));
    }

    public function test_trimmed_string_list_keeps_non_empty_trimmed_strings_only(): void
    {
        $this->assertSame(['alpha', 'beta'], AtlasAeosValueNormalizer::trimmedStringList([' alpha ', 42, '', ' beta ']));
        $this->assertSame([], AtlasAeosValueNormalizer::trimmedStringList(' alpha '));
    }

    public function test_unique_trimmed_string_list_deduplicates_after_trimming(): void
    {
        $this->assertSame(['alpha', 'beta'], AtlasAeosValueNormalizer::uniqueTrimmedStringList([' alpha ', 'alpha', ' beta ', 42]));
    }

    public function test_cast_string_list_preserves_array_shape_and_casts_items(): void
    {
        $this->assertSame([' alpha ', '42', '', ''], AtlasAeosValueNormalizer::castStringList([' alpha ', 42, null, false]));
        $this->assertSame([], AtlasAeosValueNormalizer::castStringList('alpha'));
    }

    public function test_risk_code_r0_to_r5_uses_configurable_fallback(): void
    {
        $this->assertSame('R4', AtlasAeosValueNormalizer::riskCodeR0ToR5(' r4 ', 'R1'));
        $this->assertSame('R1', AtlasAeosValueNormalizer::riskCodeR0ToR5('unknown', 'R1'));
        $this->assertSame('R0', AtlasAeosValueNormalizer::riskCodeR0ToR5(null, 'R0'));
    }

    public function test_low_medium_high_risk_uses_configurable_fallback(): void
    {
        $this->assertSame('high', AtlasAeosValueNormalizer::lowMediumHighRisk(' HIGH '));
        $this->assertSame('medium', AtlasAeosValueNormalizer::lowMediumHighRisk('unknown'));
        $this->assertSame('low', AtlasAeosValueNormalizer::lowMediumHighRisk('unknown', 'low'));
    }

    public function test_lowercase_allowed_preserves_allowed_set_and_fallback_policy(): void
    {
        $this->assertSame(
            'critical',
            AtlasAeosValueNormalizer::lowercaseAllowed(' CRITICAL ', ['low', 'medium', 'high', 'critical'], 'low'),
        );
        $this->assertSame(
            'medium',
            AtlasAeosValueNormalizer::lowercaseAllowed('unknown', ['none', 'low', 'medium', 'high'], 'medium'),
        );
        $this->assertSame(
            'high',
            AtlasAeosValueNormalizer::lowercaseAllowed(null, ['low', 'medium', 'high'], 'high'),
        );
    }

    public function test_trimmed_allowed_preserves_case_sensitive_allowed_set(): void
    {
        $this->assertSame(
            'claimed',
            AtlasAeosValueNormalizer::trimmedAllowed(' claimed ', ['available', 'claimed'], 'available'),
        );
        $this->assertSame(
            'available',
            AtlasAeosValueNormalizer::trimmedAllowed(' CLAIMED ', ['available', 'claimed'], 'available'),
        );
        $this->assertSame(
            'available',
            AtlasAeosValueNormalizer::trimmedAllowed(null, ['available', 'claimed'], 'available'),
        );
    }
}
