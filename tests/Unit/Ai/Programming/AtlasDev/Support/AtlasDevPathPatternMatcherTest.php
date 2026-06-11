<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevPathPatternMatcher;
use PHPUnit\Framework\TestCase;

final class AtlasDevPathPatternMatcherTest extends TestCase
{
    public function test_matches_exact_path(): void
    {
        $this->assertTrue(AtlasDevPathPatternMatcher::matchesAny('app/Foo.php', ['app/Foo.php']));
    }

    public function test_matches_glob_with_noescape_contract(): void
    {
        $this->assertTrue(AtlasDevPathPatternMatcher::matchesAny('app/Services/Foo.php', ['app/Services/*']));
    }

    public function test_matches_recursive_directory_glob(): void
    {
        $this->assertTrue(AtlasDevPathPatternMatcher::matchesAny('app/Services/Nested/Foo.php', ['app/**']));
        $this->assertFalse(AtlasDevPathPatternMatcher::matchesAny('database/migrations/x.php', ['app/**']));
    }

    public function test_matches_question_mark_glob(): void
    {
        $this->assertTrue(AtlasDevPathPatternMatcher::matchesAny('app/Foo1.php', ['app/Foo?.php']));
    }

    public function test_matches_directory_prefix_pattern(): void
    {
        $this->assertTrue(AtlasDevPathPatternMatcher::matchesAny('app/Services/Foo.php', ['app/Services/']));
    }

    public function test_rejects_non_matching_patterns(): void
    {
        $this->assertFalse(AtlasDevPathPatternMatcher::matchesAny('app/Foo.php', ['tests/*', 'app/Bar.php']));
    }
}
