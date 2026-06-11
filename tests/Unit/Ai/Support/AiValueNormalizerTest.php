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
}
