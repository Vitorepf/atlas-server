<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCloneOrderedNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * §5.6 · DEDUP — the normalizer must be the seam neither existing public path provides: ORDER-preserving AND
 * literal/identifier-AWARE, collapsing ONLY variable renames. These properties are what make clone-unification
 * admissibility safe (no behavior-breaking false-clone unification).
 */
final class AtlasLoopCloneOrderedNormalizerTest extends TestCase
{
    private function n(): AtlasLoopCloneOrderedNormalizer
    {
        return new AtlasLoopCloneOrderedNormalizer;
    }

    public function test_order_sensitive_two_statements_swapped_are_not_equivalent(): void
    {
        // The order-insensitive-Jaccard worst-failure: a();b() must NOT equal b();a().
        $this->assertFalse($this->n()->equivalent('foo(); bar();', 'bar(); foo();'));
    }

    public function test_literal_sensitive_different_string_literals_are_not_equivalent(): void
    {
        // The silent wrong-config-key unification: config('x') must NOT equal config('y').
        $this->assertFalse($this->n()->equivalent("config('atlas.loop.x');", "config('atlas.loop.y');"));
    }

    public function test_numeric_literal_divergence_is_not_equivalent(): void
    {
        $this->assertFalse($this->n()->equivalent('return 1;', 'return 2;'));
    }

    public function test_identifier_sensitive_different_call_targets_are_not_equivalent(): void
    {
        $this->assertFalse($this->n()->equivalent('alpha($x);', 'beta($x);'));
    }

    public function test_variable_rename_is_tolerated(): void
    {
        // A pure rename is behavior-preserving — these ARE clones.
        $this->assertTrue($this->n()->equivalent(
            '$a = 1; return $a + 1;',
            '$total = 1; return $total + 1;',
        ));
    }

    public function test_whitespace_and_comments_are_insensitive(): void
    {
        $this->assertTrue($this->n()->equivalent(
            "foo();\n    bar(); // a comment\n",
            "foo(); bar();",
        ));
    }

    public function test_genuinely_identical_bodies_share_a_hash(): void
    {
        $a = '$out = []; foreach ($items as $k => $v) { if ($v > 0) { $out[$k] = $v * 2; } } return $out;';
        $b = '$result = []; foreach ($rows as $key => $val) { if ($val > 0) { $result[$key] = $val * 2; } } return $result;';
        $this->assertSame($this->n()->hash($a), $this->n()->hash($b), 'rename-only clones share a hash');
    }

    public function test_a_behavioral_divergence_breaks_the_hash(): void
    {
        $a = '$out = []; foreach ($items as $v) { $out[] = $v * 2; } return $out;';
        $b = '$out = []; foreach ($items as $v) { $out[] = $v * 3; } return $out;'; // 2 -> 3
        $this->assertNotSame($this->n()->hash($a), $this->n()->hash($b));
    }
}
