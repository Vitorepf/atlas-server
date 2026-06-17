<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopChangedSymbolCoverageCensus;
use PHPUnit\Framework\TestCase;

/**
 * ACDE V1 — the changed-symbol coverage census's stronger "exercised" anchor: a changed public symbol must be
 * CALLED in the corpus AND the corpus must assert something. Pure, no git/DB => hang-free. Proves the lift over
 * bare name-presence: a symbol merely mentioned in a comment no longer satisfies the census.
 */
final class AtlasLoopSymbolExercisedTest extends TestCase
{
    private function census(): AtlasLoopChangedSymbolCoverageCensus
    {
        return new AtlasLoopChangedSymbolCoverageCensus;
    }

    public function test_called_and_asserted_is_exercised(): void
    {
        $corpus = "<?php\nclass T { public function test_it(){ \$this->assertSame(4, computeWidget(2)); } }";

        $this->assertTrue($this->census()->symbolExercised($corpus, 'computeWidget'));
    }

    public function test_merely_named_in_a_comment_is_not_exercised(): void
    {
        $corpus = "<?php\n// this test is about computeWidget behavior\nclass T { public function test_it(){ \$this->assertTrue(true); } }";

        $this->assertFalse($this->census()->symbolExercised($corpus, 'computeWidget'), 'named in a comment but never called => not exercised');
    }

    public function test_called_without_any_assertion_is_not_exercised(): void
    {
        $corpus = "<?php\nclass T { public function test_it(){ computeWidget(2); } }";

        $this->assertFalse($this->census()->symbolExercised($corpus, 'computeWidget'), 'called but the corpus asserts nothing => not exercised');
    }

    public function test_absent_symbol_is_not_exercised(): void
    {
        $corpus = "<?php\nclass T { public function test_it(){ \$this->assertTrue(true); } }";

        $this->assertFalse($this->census()->symbolExercised($corpus, 'computeWidget'));
    }

    public function test_expect_style_assertion_also_counts(): void
    {
        $corpus = "<?php\nit('works', function(){ expect(computeWidget(2))->toBe(4); });";

        $this->assertTrue($this->census()->symbolExercised($corpus, 'computeWidget'));
    }
}
