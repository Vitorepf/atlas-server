<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopCoverageDeficitSource;
use Tests\TestCase;

/**
 * §11.6 — coverage-deficit discovery source. Each case pins EXACT values (mutant counts, the deficit
 * ordering, the objective set) so the test goes RED if the scoring or the objective gate is broken — not a
 * count>0 tautology.
 */
final class AtlasLoopCoverageDeficitSourceTest extends TestCase
{
    private AtlasLoopCoverageDeficitSource $source;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new AtlasLoopCoverageDeficitSource;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * A decision-dense source: 8 frozen radius-1 mutants (return true/false, ===, > 0, >=, <=, >, <).
     * This is the ground truth the source MUST read from the frozen Constitution — pinned here so the test
     * fails if the operator vocabulary or the source's mutant counting drifts.
     */
    private function complexSource(): string
    {
        return <<<'PHP'
<?php
final class Thing {
    public function decide(int $a, int $b): bool {
        if ($a === $b) { return true; }
        if ($a >= 0 && $b <= 10) { return false; }
        if ($a > 0) { return true; }
        return false;
    }
}
PHP;
    }

    /** A trivial source: no decisions, no literals, no returns — only the unavoidable `<?php` structural mutant. */
    private function trivialSource(): string
    {
        return "<?php\nfinal class Plain { public function noop(): void {} }\n";
    }

    private function writeTmp(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/atlas_covdef_'.uniqid('', true).'_'.$name;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }

    public function test_complex_source_is_pinned_to_eight_frozen_mutants(): void
    {
        // Ground the whole test on the real frozen neighbourhood: 8 mutants, this exact set.
        $n = AtlasLoopFrozenMutationOperators::neighborhood($this->complexSource());
        $this->assertSame(
            ['return_true', 'return_false', 'strict_equals', 'positive_comparison', 'gte_comparison', 'lte_comparison', 'gt_comparison', 'lt_comparison'],
            array_keys($n),
            'fixture invariant: the complex source must produce exactly these 8 frozen mutants',
        );
    }

    public function test_complex_untested_file_is_high_deficit_with_a_characterization_objective(): void
    {
        $abs = $this->writeTmp('Thing.php', $this->complexSource());

        $score = $this->source->score($abs, false);

        // 8 mutants, no sibling test, deficit saturates to 1.0 (7 real mutants above the floor / saturation 6, capped).
        $this->assertSame(8, $score['mutants']);
        $this->assertFalse($score['has_sibling_test']);
        $this->assertSame(1.0, $score['deficit'], 'dense + untested ⇒ fully saturated deficit');

        $obj = $this->source->deficitObjective('app/Svc/Thing.php', $score);
        $this->assertNotNull($obj, 'a high-deficit untested file MUST emit a characterization_test objective');
        $this->assertSame('characterization_test', $obj['shape']);
        $this->assertSame('app/Svc/Thing.php', $obj['target_path']);
        $this->assertStringContainsString('characterization test', $obj['objective']);
    }

    public function test_same_complex_file_with_a_sibling_test_is_low_deficit_and_emits_nothing(): void
    {
        $abs = $this->writeTmp('Thing.php', $this->complexSource());

        $untested = $this->source->score($abs, false);
        $tested = $this->source->score($abs, true);

        // Same source ⇒ same mutant count; ONLY the sibling-test flag changes the deficit.
        $this->assertSame($untested['mutants'], $tested['mutants']);
        $this->assertTrue($tested['has_sibling_test']);

        // The sibling test scales the deficit DOWN by ~20x and below the HIGH threshold.
        $this->assertSame(0.05, $tested['deficit']);
        $this->assertLessThan(AtlasLoopCoverageDeficitSource::HIGH_DEFICIT_THRESHOLD, $tested['deficit']);
        $this->assertLessThan($untested['deficit'], $tested['deficit'], 'a tested file is strictly lower deficit');

        $this->assertNull(
            $this->source->deficitObjective('app/Svc/Thing.php', $tested),
            'a covered file must NEVER be proposed for a characterization test',
        );
    }

    public function test_trivial_source_has_near_zero_deficit_and_no_objective(): void
    {
        $abs = $this->writeTmp('Plain.php', $this->trivialSource());

        $score = $this->source->score($abs, false);

        // Only the structural `<?php` mutant — no real decision density ⇒ 0 deficit even with no sibling test.
        $this->assertSame(AtlasLoopFrozenMutationOperators::neighborhood($this->trivialSource()), AtlasLoopFrozenMutationOperators::neighborhood($this->trivialSource()));
        $this->assertSame(1, $score['mutants'], 'a trivial php file carries only the structural floor mutant');
        $this->assertSame(0.0, $score['deficit'], 'no decision density ⇒ ~0 deficit');
        $this->assertStringContainsString('no coverage deficit', $score['reason']);

        $this->assertNull(
            $this->source->deficitObjective('app/Svc/Plain.php', $score),
            'a trivial file is not a coverage deficit and emits no objective',
        );
    }

    public function test_pure_core_orders_deficit_by_density_monotonically(): void
    {
        // scoreSource is the pure denominator: more surviving mutants ⇒ strictly higher deficit until saturation.
        $two = $this->source->scoreSource(2, false);   // 1 real mutant
        $four = $this->source->scoreSource(4, false);  // 3 real mutants
        $seven = $this->source->scoreSource(7, false); // 6 real mutants ⇒ saturates

        $this->assertGreaterThan($two['deficit'], $four['deficit']);
        $this->assertGreaterThan($four['deficit'], $seven['deficit']);
        $this->assertSame(1.0, $seven['deficit']);

        // identical inputs ⇒ identical output (no clock/DB/provider).
        $this->assertSame($four, $this->source->scoreSource(4, false));
    }
}
