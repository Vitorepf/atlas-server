<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use Tests\TestCase;

final class AtlasAaeosStringListNormalizerTest extends TestCase
{
    public function test_unique_sorted_strings_preserves_empty_string_contract(): void
    {
        $this->assertSame(
            ['', 'alpha', 'beta'],
            AtlasAaeosStringListNormalizer::uniqueSortedStrings(['beta', '', 'alpha', 'beta', '']),
        );
    }
}
