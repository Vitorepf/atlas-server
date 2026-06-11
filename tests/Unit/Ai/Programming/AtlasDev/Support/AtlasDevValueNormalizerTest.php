<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevValueNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasDevValueNormalizerTest extends TestCase
{
    public function test_string_or_null_trims_strings_and_rejects_non_strings(): void
    {
        $this->assertSame('run-1', AtlasDevValueNormalizer::stringOrNull(' run-1 '));
        $this->assertNull(AtlasDevValueNormalizer::stringOrNull('  '));
        $this->assertNull(AtlasDevValueNormalizer::stringOrNull(42));
        $this->assertNull(AtlasDevValueNormalizer::stringOrNull(null));
    }
}
