<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAtomParaphraseAudit;
use PHPUnit\Framework\TestCase;

/**
 * ACDE F8 (honest, non-Goodhart form) — the paraphrase audit flags near-duplicate HUMAN atom pairs as an
 * advisory. It NEVER infers, authors, weakens, removes, or grades an atom — it only points at a possible
 * authoring redundancy for the operator. Pure + deterministic.
 */
final class AtlasLoopAtomParaphraseAuditTest extends TestCase
{
    private function svc(): AtlasLoopAtomParaphraseAudit
    {
        return new AtlasLoopAtomParaphraseAudit;
    }

    public function test_flags_near_paraphrase_pairs_only(): void
    {
        $atoms = [
            ['id' => 'c1', 'description' => 'the parser returns null on empty input'],
            ['id' => 'c2', 'description' => 'the parser returns null on an empty input'],   // near-paraphrase of c1
            ['id' => 'c3', 'description' => 'logging is emitted for every failed request'], // unrelated
        ];

        $pairs = $this->svc()->nearDuplicatePairs($atoms, 0.7);

        $this->assertCount(1, $pairs, 'only the near-paraphrase pair is flagged');
        $this->assertSame(0, $pairs[0]['a']);
        $this->assertSame(1, $pairs[0]['b']);
        $this->assertGreaterThanOrEqual(0.7, $pairs[0]['similarity']);
        // The advisory carries the original human texts verbatim — it never rewrites them.
        $this->assertSame('the parser returns null on empty input', $pairs[0]['a_text']);
    }

    public function test_distinct_atoms_produce_no_advisory(): void
    {
        $atoms = [
            ['description' => 'alpha beta gamma'],
            ['description' => 'delta epsilon zeta'],
            ['description' => 'eta theta iota'],
        ];
        $this->assertSame([], $this->svc()->nearDuplicatePairs($atoms, 0.85));
    }

    public function test_threshold_zero_disables_the_audit(): void
    {
        $atoms = [['description' => 'same words here'], ['description' => 'same words here']];
        $this->assertSame([], $this->svc()->nearDuplicatePairs($atoms, 0.0), 'threshold 0 => disabled, no pairs');
    }

    public function test_reads_text_from_common_atom_shapes_and_skips_empty(): void
    {
        $atoms = [
            ['text' => 'normalize the user email before saving'],
            ['criterion' => 'normalize the user email before saving'],
            ['id' => 'unmatched-criterion'],          // only an id, distinct words
            ['description' => ''],                      // empty => never matched
        ];
        $pairs = $this->svc()->nearDuplicatePairs($atoms, 0.9);

        $this->assertCount(1, $pairs, 'text and criterion shapes both resolve; the empty atom is skipped');
        $this->assertSame(0, $pairs[0]['a']);
        $this->assertSame(1, $pairs[0]['b']);
    }
}
