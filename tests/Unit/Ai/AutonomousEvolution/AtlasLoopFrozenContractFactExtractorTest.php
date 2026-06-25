<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;
use App\Services\Ai\AutonomousEvolution\FrozenContracts\AtlasLoopFrozenContractFactExtractor;
use Tests\TestCase;

final class AtlasLoopFrozenContractFactExtractorTest extends TestCase
{
    public function test_extract_against_a_real_frozen_contract_returns_non_empty_invariants_and_anchors_with_no_scalar_score(): void
    {
        $facts = (new AtlasLoopFrozenContractFactExtractor)->extract(AtlasLoopFrozenMutationOperators::class);

        $this->assertSame(AtlasLoopFrozenContractFactExtractor::SCHEMA, $facts['schema']);
        $this->assertNotEmpty($facts['invariants'], 'a real frozen-contract docblock must yield invariants');
        $this->assertNotEmpty($facts['anchors'], 'a real frozen-contract docblock must yield @see anchors');
        $this->assertArrayNotHasKey('score', $facts, 'anti-Goodhart: no scalar score');
        $this->assertArrayNotHasKey('level', $facts);
        $this->assertArrayNotHasKey('rank', $facts);
    }

    public function test_extract_is_byte_deterministic_across_two_runs(): void
    {
        $a = (new AtlasLoopFrozenContractFactExtractor)->extract(AtlasLoopFrozenMutationOperators::class);
        $b = (new AtlasLoopFrozenContractFactExtractor)->extract(AtlasLoopFrozenMutationOperators::class);

        $this->assertSame(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR));
    }

    public function test_extract_handles_a_synthetic_class_with_known_markers(): void
    {
        eval(<<<'PHP'
namespace Tests\Unit\Ai\AutonomousEvolution\Synthetic {
    /**
     * @see App\Other\Anchor1
     * @see Anchor2
     *
     * INVARIANT: the kill vocabulary must NEVER shrink.
     * PETREO: the gate may only widen.
     * FORBIDDEN: silently dropping a mutant.
     * Contract version Slice 7.
     */
    final class FrozenSyntheticContract {}
}
PHP);

        $facts = (new AtlasLoopFrozenContractFactExtractor)->extract('Tests\\Unit\\Ai\\AutonomousEvolution\\Synthetic\\FrozenSyntheticContract');

        $this->assertCount(3, $facts['invariants'], 'three explicit INVARIANT/PETREO/FORBIDDEN lines');
        $this->assertCount(1, $facts['forbidden_mutations']);
        $this->assertContains('App\\Other\\Anchor1', $facts['anchors']);
        $this->assertContains('Anchor2', $facts['anchors']);
        $this->assertSame('Slice 7', $facts['contract_version']);
    }

    public function test_removing_an_invariant_line_changes_the_returned_invariants_list(): void
    {
        eval(<<<'PHP'
namespace Tests\Unit\Ai\AutonomousEvolution\SyntheticOriginal {
    /**
     * INVARIANT: A
     * INVARIANT: B
     * INVARIANT: C
     */
    final class Original {}
}
namespace Tests\Unit\Ai\AutonomousEvolution\SyntheticMutated {
    /**
     * INVARIANT: A
     * INVARIANT: B
     */
    final class Mutated {}
}
PHP);

        $extractor = new AtlasLoopFrozenContractFactExtractor;
        $original = $extractor->extract('Tests\\Unit\\Ai\\AutonomousEvolution\\SyntheticOriginal\\Original')['invariants'];
        $mutated = $extractor->extract('Tests\\Unit\\Ai\\AutonomousEvolution\\SyntheticMutated\\Mutated')['invariants'];

        $this->assertCount(3, $original);
        $this->assertCount(2, $mutated);
        $this->assertNotSame($original, $mutated, 'removing one INVARIANT line must change the extracted list');
    }
}
