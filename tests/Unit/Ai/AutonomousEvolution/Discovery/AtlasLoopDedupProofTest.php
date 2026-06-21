<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupProof;
use PHPUnit\Framework\TestCase;

/**
 * §5.6 · DEDUP — the count-drop measure: a clone-unification is REAL iff the shared clone body went from ≥2
 * member files to <2 (duplication structurally removed). A no-op "dedup" (add a helper, leave both bodies) is
 * REJECTED. No baseline clone ⇒ null (fail-closed). Pure (source-in/result-out), so the worst-failure (a
 * laundered no-op) is closed deterministically here, independent of the judge's git plumbing.
 */
final class AtlasLoopDedupProofTest extends TestCase
{
    private function proof(): AtlasLoopDedupProof
    {
        return new AtlasLoopDedupProof;
    }

    private function classWithBody(string $class, string $body): string
    {
        return "<?php\n\nnamespace App\\X;\n\nfinal class {$class}\n{\n    public function transform(array \$items): array\n    {\n{$body}\n    }\n}\n";
    }

    /** The shared clone body (rename-tolerant between members). */
    private function cloneBody(string $var): string
    {
        return "        \${$var} = [];\n        foreach (\$items as \$k => \$v) {\n            if (\$v > 0) {\n                \${$var}[\$k] = \$v * 2;\n            }\n        }\n\n        return \${$var};";
    }

    public function test_body_hashes_finds_a_method_body(): void
    {
        $this->assertCount(1, $this->proof()->bodyHashes($this->classWithBody('A', $this->cloneBody('out'))));
    }

    public function test_a_genuine_unification_removing_both_bodies_is_REMOVED(): void
    {
        // BASELINE: two files share the clone body (rename-only) => baseline_count 2.
        $baseline = [
            'app/X/A.php' => $this->classWithBody('A', $this->cloneBody('out')),
            'app/X/B.php' => $this->classWithBody('B', $this->cloneBody('result')),
        ];
        // CANDIDATE: BOTH delegate to a shared unit — the clone body is gone from both.
        $delegated = "        return \\App\\X\\Shared::transform(\$items);";
        $candidate = [
            'app/X/A.php' => $this->classWithBody('A', $delegated),
            'app/X/B.php' => $this->classWithBody('B', $delegated),
        ];

        $r = $this->proof()->evaluate($baseline, $candidate);
        $this->assertIsArray($r);
        $this->assertSame(2, $r['baseline_count']);
        $this->assertSame(0, $r['candidate_count']);
        $this->assertTrue($r['removed'], 'duplication structurally removed (both bodies delegated) => earned');
    }

    public function test_a_no_op_that_keeps_both_bodies_is_NOT_removed(): void
    {
        // The anti-farm worst-failure: add a shared helper but LEAVE both clone bodies intact.
        $baseline = [
            'app/X/A.php' => $this->classWithBody('A', $this->cloneBody('out')),
            'app/X/B.php' => $this->classWithBody('B', $this->cloneBody('result')),
        ];
        // CANDIDATE: identical to baseline (the "dedup" added a helper elsewhere but didn't touch the bodies).
        $r = $this->proof()->evaluate($baseline, $baseline);
        $this->assertIsArray($r);
        $this->assertSame(2, $r['baseline_count']);
        $this->assertSame(2, $r['candidate_count']);
        $this->assertFalse($r['removed'], 'a no-op dedup (bodies untouched) is REJECTED — the count did not drop');
    }

    public function test_partial_unification_two_to_one_still_counts_as_removed(): void
    {
        $baseline = [
            'app/X/A.php' => $this->classWithBody('A', $this->cloneBody('out')),
            'app/X/B.php' => $this->classWithBody('B', $this->cloneBody('result')),
        ];
        $candidate = [
            'app/X/A.php' => $this->classWithBody('A', '        return \\App\\X\\Shared::transform($items);'),
            'app/X/B.php' => $this->classWithBody('B', $this->cloneBody('result')), // one still has the body
        ];
        $r = $this->proof()->evaluate($baseline, $candidate);
        $this->assertSame(1, $r['candidate_count']);
        $this->assertTrue($r['removed'], '2 -> 1 (<2) is a real reduction of the duplication');
    }

    public function test_no_baseline_clone_is_null_fail_closed(): void
    {
        $baseline = [
            'app/X/A.php' => $this->classWithBody('A', $this->cloneBody('out')),
            'app/X/B.php' => $this->classWithBody('B', '        return count($items);'), // different body
        ];
        $this->assertNull($this->proof()->evaluate($baseline, $baseline), 'no duplication to remove => fail-closed null');
    }
}
