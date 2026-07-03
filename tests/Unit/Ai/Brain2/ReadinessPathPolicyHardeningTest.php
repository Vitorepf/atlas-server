<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ReadinessPathPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves ReadinessPathPolicy::pathMatches does not match unrelated paths for a
 * bare /** pattern (degenerate glob that would collapse to an empty prefix).
 */
final class ReadinessPathPolicyHardeningTest extends TestCase
{
    // ── Degenerate /** pattern ───────────────────────────────────────────────────

    public function test_bare_glob_does_not_match_unrelated_paths(): void
    {
        $this->assertFalse(ReadinessPathPolicy::pathMatches('app/Services/Foo.php', '/**'));
        $this->assertFalse(ReadinessPathPolicy::pathMatches('/some/path/file.php', '/**'));
        $this->assertFalse(ReadinessPathPolicy::pathMatches('tests/Unit/BarTest.php', '/**'));
    }

    public function test_bare_glob_matches_only_exact_pattern(): void
    {
        // pathMatches returns true when path === pattern (exact match).
        $this->assertTrue(ReadinessPathPolicy::pathMatches('/**', '/**'));
    }

    // ── Normal glob patterns still work ──────────────────────────────────────────

    public function test_valid_glob_matches_correct_paths(): void
    {
        $this->assertTrue(ReadinessPathPolicy::pathMatches('app/Services/Foo.php', 'app/Services/**'));
        $this->assertTrue(ReadinessPathPolicy::pathMatches('app/Services/Deep/Nested/Bar.php', 'app/Services/**'));
        $this->assertFalse(ReadinessPathPolicy::pathMatches('tests/Unit/FooTest.php', 'app/Services/**'));
    }

    public function test_single_char_prefix_glob_works(): void
    {
        $this->assertTrue(ReadinessPathPolicy::pathMatches('a/foo.php', 'a/**'));
        $this->assertFalse(ReadinessPathPolicy::pathMatches('b/foo.php', 'a/**'));
    }

    // ── classifyPath with degenerate glob ────────────────────────────────────────

    public function test_classify_path_with_degenerate_glob_does_not_false_match(): void
    {
        $result = ReadinessPathPolicy::classifyPath(
            'app/Services/Foo.php',
            allowed: [],
            forbidden: ['/**'],
        );

        // A bare /** should not match arbitrary paths.
        $this->assertSame('unknown', $result);
    }
}
