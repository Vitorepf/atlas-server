<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiValueNormalizer;
use Tests\TestCase;

final class AiValueNormalizerTest extends TestCase
{
    public function test_trimmed_string_or_null_accepts_non_blank_strings_only(): void
    {
        $this->assertSame('alpha', AiValueNormalizer::trimmedStringOrNull(' alpha '));
        $this->assertSame('0', AiValueNormalizer::trimmedStringOrNull(' 0 '));
        $this->assertNull(AiValueNormalizer::trimmedStringOrNull('   '));
        $this->assertNull(AiValueNormalizer::trimmedStringOrNull(42));
        $this->assertNull(AiValueNormalizer::trimmedStringOrNull(null));
    }

    public function test_trimmed_scalar_string_or_null_preserves_scalar_cast_contract(): void
    {
        $this->assertSame('alpha', AiValueNormalizer::trimmedScalarStringOrNull(' alpha '));
        $this->assertSame('42', AiValueNormalizer::trimmedScalarStringOrNull(42));
        $this->assertSame('1', AiValueNormalizer::trimmedScalarStringOrNull(true));
        $this->assertNull(AiValueNormalizer::trimmedScalarStringOrNull(false));
        $this->assertNull(AiValueNormalizer::trimmedScalarStringOrNull('   '));
        $this->assertNull(AiValueNormalizer::trimmedScalarStringOrNull([]));
    }

    public function test_clamp_unit_bounds_to_closed_unit_interval(): void
    {
        $this->assertSame(0.0, AiValueNormalizer::clampUnit(-0.2));
        $this->assertSame(0.42, AiValueNormalizer::clampUnit(0.42));
        $this->assertSame(1.0, AiValueNormalizer::clampUnit(1.7));
    }

    public function test_array_or_empty_rejects_non_arrays(): void
    {
        $this->assertSame(['a' => 1], AiValueNormalizer::arrayOrEmpty(['a' => 1]));
        $this->assertSame([], AiValueNormalizer::arrayOrEmpty(null));
        $this->assertSame([], AiValueNormalizer::arrayOrEmpty('x'));
    }

    public function test_trimmed_and_lower_trimmed_string_helpers(): void
    {
        $this->assertSame('Alpha', AiValueNormalizer::trimmedString(' Alpha '));
        $this->assertSame('alpha', AiValueNormalizer::lowerTrimmedString(' Alpha '));
        $this->assertSame('', AiValueNormalizer::trimmedString(null));
    }
}
