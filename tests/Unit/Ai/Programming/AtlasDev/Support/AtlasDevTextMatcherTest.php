<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevTextMatcher;
use PHPUnit\Framework\TestCase;

final class AtlasDevTextMatcherTest extends TestCase
{
    public function test_contains_any_matches_substrings_and_skips_empty_needles(): void
    {
        $this->assertTrue(AtlasDevTextMatcher::containsAny('corrija app/Foo.php', ['', 'app/']));
        $this->assertFalse(AtlasDevTextMatcher::containsAny('corrija app/Foo.php', ['', 'tests/']));
    }
}
