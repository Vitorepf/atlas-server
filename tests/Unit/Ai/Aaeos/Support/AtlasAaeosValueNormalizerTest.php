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
}
