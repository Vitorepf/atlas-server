<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCloneDetector;
use Tests\TestCase;

/**
 * §11.6 — cross-file clone detection (dedup, the canon core value). Every case pins EXACT similarity bands
 * and the EXACT clone-pair SET, never count>0 — so the test goes RED if the tokenizer, name-normalization,
 * or the multiset Jaccard is broken.
 */
final class AtlasLoopCloneDetectorTest extends TestCase
{
    private AtlasLoopCloneDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AtlasLoopCloneDetector;
    }

    /** Base function: a small piece of decision logic over named variables. */
    private function original(): string
    {
        return <<<'PHP'
<?php
function sumPositive(array $items): int {
    $total = 0;
    foreach ($items as $value) {
        if ($value > 0) {
            $total = $total + $value;
        }
    }
    return $total;
}
PHP;
    }

    /** Byte-for-byte the same LOGIC as original() but every variable renamed and reindented/recommented. */
    private function renamedAndReformatted(): string
    {
        return <<<'PHP'
<?php

// accumulate the strictly-positive entries
function sumPositive(array $entries): int
{
        $acc      = 0;   // running sum
        foreach ($entries as $element) {
            if ($element > 0) {
                $acc = $acc + $element;
            }
        }

        return $acc;
    }
PHP;
    }

    /**
     * Genuinely different STRUCTURE: a class with typed properties + a switch, no function/foreach/return-of-
     * accumulator skeleton. Shares almost no token kinds with original() — should NOT read as a clone.
     */
    private function structurallyDifferent(): string
    {
        return <<<'PHP'
<?php
final class HttpStatus {
    public string $phrase = 'OK';
    public function category(int $code): string {
        switch (intdiv($code, 100)) {
            case 2: return 'success';
            case 4: return 'client_error';
            default: return 'other';
        }
    }
}
PHP;
    }

    public function test_renamed_and_reformatted_copy_is_near_identical(): void
    {
        $sim = $this->detector->similarity($this->original(), $this->renamedAndReformatted());

        // Variable rename + whitespace + comment changes must NOT lower structural similarity below 0.9;
        // the two are structurally identical modulo names, so it should be ~1.0.
        $this->assertGreaterThanOrEqual(0.9, $sim, 'renamed+reformatted clone must score ~1.0');
        $this->assertLessThanOrEqual(1.0, $sim);
    }

    public function test_identical_modulo_names_is_exactly_one(): void
    {
        // Same logic, ONLY the variable names differ (no comment/whitespace divergence in token kinds) ⇒ 1.0.
        $a = "<?php\n\$x = 1 + 2;\nreturn \$x;\n";
        $b = "<?php\n\$result = 1 + 2;\nreturn \$result;\n";

        $this->assertSame(1.0, $this->detector->similarity($a, $b), 'rename-only clone is exactly 1.0');
    }

    public function test_self_similarity_is_one(): void
    {
        $this->assertSame(1.0, $this->detector->similarity($this->original(), $this->original()));
    }

    public function test_structurally_different_sources_are_low_similarity(): void
    {
        $sim = $this->detector->similarity($this->original(), $this->structurallyDifferent());

        $this->assertLessThan(0.5, $sim, 'disjoint logic must score well under the clone threshold');
    }

    public function test_detect_clones_returns_exactly_the_clone_pair_and_excludes_the_outlier(): void
    {
        $files = [
            'app/A.php' => $this->original(),
            'app/B.php' => $this->renamedAndReformatted(),
            'app/C.php' => $this->structurallyDifferent(),
        ];

        $pairs = $this->detector->detectClones($files, 0.85);

        // EXACT set: only the A/B clone, never A/C or B/C, never a self-pair, never a (b,a) duplicate.
        $this->assertCount(1, $pairs);
        $this->assertSame('app/A.php', $pairs[0]['a']);
        $this->assertSame('app/B.php', $pairs[0]['b']);
        $this->assertGreaterThanOrEqual(0.9, $pairs[0]['similarity']);
    }

    public function test_exact_copy_trio_yields_all_three_pairs_a_lt_b(): void
    {
        // Three exact copies (modulo names) ⇒ all C(3,2) = 3 ordered pairs, a<b, no self, no dup.
        $files = [
            'app/X.php' => $this->original(),
            'app/Y.php' => $this->renamedAndReformatted(),
            'app/Z.php' => $this->original(),
        ];

        $pairs = $this->detector->detectClones($files, 0.85);

        $set = array_map(static fn (array $p): string => $p['a'].'|'.$p['b'], $pairs);
        sort($set, SORT_STRING);

        $this->assertSame(
            ['app/X.php|app/Y.php', 'app/X.php|app/Z.php', 'app/Y.php|app/Z.php'],
            $set,
            'an exact-copy trio must yield exactly the three a<b pairs',
        );
    }

    public function test_no_pair_below_threshold(): void
    {
        $files = [
            'app/A.php' => $this->original(),
            'app/C.php' => $this->structurallyDifferent(),
        ];

        $this->assertSame([], $this->detector->detectClones($files, 0.85), 'no structural clone ⇒ empty set');
    }

    public function test_dedup_objective_targets_first_file_with_dedup_shape(): void
    {
        $pair = ['a' => 'app/A.php', 'b' => 'app/B.php', 'similarity' => 0.97];

        $obj = $this->detector->dedupObjective($pair);

        $this->assertSame('dedup', $obj['shape']);
        $this->assertSame('app/A.php', $obj['target_path']);
        $this->assertStringContainsString('app/A.php', $obj['objective']);
        $this->assertStringContainsString('app/B.php', $obj['objective']);
        $this->assertStringContainsString('dedupe', $obj['objective']);
    }

    public function test_empty_sources_are_vacuously_identical(): void
    {
        // Two structure-only sources (just an open tag) have empty normalized streams ⇒ defined as 1.0.
        $this->assertSame(1.0, $this->detector->similarity('<?php', '<?php'));
    }

    public function test_similarity_is_deterministic_and_symmetric(): void
    {
        $s1 = $this->detector->similarity($this->original(), $this->renamedAndReformatted());
        $s2 = $this->detector->similarity($this->renamedAndReformatted(), $this->original());

        $this->assertSame($s1, $s2, 'multiset Jaccard is symmetric');
        $this->assertSame($s1, $this->detector->similarity($this->original(), $this->renamedAndReformatted()));
    }
}
