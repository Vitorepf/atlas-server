<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use Tests\TestCase;

/**
 * §2 · GROUNDING VETO — the STRICT, fail-CLOSED grounding for comprehension work: a cited symbol must be a
 * MEMBER of the brain's own inventory (exact FQCN / rel-path / class-name), never a repo-wide basename scan.
 * A citation absent from the inventory is REFUTED (a hard veto, not advisory); empty citations are refuted.
 * This kills the hallucinated-citation farm the fail-open gate let through.
 */
final class AtlasLoopGroundingVetoTest extends TestCase
{
    /** @return list<array{rel_path:string, fqcn:string}> */
    private function inventory(): array
    {
        return [
            ['rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopResearchOriginator.php', 'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator'],
            ['rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopTransferGate.php', 'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopTransferGate'],
        ];
    }

    public function test_a_real_inventory_member_grounds_by_fqcn_path_or_class_name(): void
    {
        $gate = new AtlasLoopComprehensionGroundingGate;
        foreach ([
            'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator', // exact fqcn
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTransferGate.php',         // exact rel-path
            'AtlasLoopResearchOriginator',                                            // bare class-name of a member
        ] as $citation) {
            $res = $gate->groundAgainstInventory('wire it', [$citation], $this->inventory());
            $this->assertTrue($res['grounded'], "citation $citation must ground against the inventory");
            $this->assertSame([], $res['refuted']);
        }
    }

    public function test_a_citation_absent_from_the_inventory_is_refuted_and_vetoes(): void
    {
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory(
            'wire it',
            ['App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopHallucinatedThing'],
            $this->inventory(),
        );
        $this->assertFalse($res['grounded'], 'a symbol the brain map does not contain is VETOED');
        $this->assertContains('App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopHallucinatedThing', $res['refuted']);
    }

    public function test_no_repo_wide_basename_false_positive(): void
    {
        // The old fail-open gate matched any *Builder.php basename across the whole repo (56 false positives).
        // Strict inventory grounding refutes a Builder name that is NOT an inventory member.
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory('x', ['SomeRandomBuilder'], $this->inventory());
        $this->assertFalse($res['grounded'], 'a basename not in the inventory must NOT false-positive');
    }

    public function test_empty_citations_are_refuted_fail_closed(): void
    {
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory('x', [], $this->inventory());
        $this->assertFalse($res['grounded'], 'fail-CLOSED: a comprehension objective with no citation is not grounded');
        $this->assertSame(0, $res['citation_count']);
    }

    public function test_majority_anchored_objective_grounds_dropping_the_loose_minority(): void
    {
        // ANCHORED (default 0.5): 2 real inventory members + 1 loose citation (ratio 0.67) is grounded, not
        // vetoed — the old all-or-nothing rule strangled exactly this (a real objective citing a doc/helper).
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory('wire it', [
            'AtlasLoopResearchOriginator',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopTransferGate.php',
            'docs/loop-os-architecture.md', // a doc — never an inventory code member
        ], $this->inventory());

        $this->assertTrue($res['grounded'], 'a majority-anchored objective grounds');
        $this->assertContains('docs/loop-os-architecture.md', $res['refuted'], 'the loose citation is surfaced');
        $this->assertCount(2, $res['resolved'], 'both real members resolve');
    }

    public function test_minority_resolved_is_still_vetoed(): void
    {
        // 1 real among 3 fakes (ratio 0.25 < 0.5) is the hallucination tell — still VETOED.
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory('x', [
            'AtlasLoopResearchOriginator', 'GhostA', 'GhostB', 'GhostC',
        ], $this->inventory());
        $this->assertFalse($res['grounded'], 'a minority-resolved citation set is still hallucination-vetoed');
    }

    public function test_threshold_one_restores_strict_all_or_nothing(): void
    {
        config(['atlas.loop.grounding_inventory_min_resolved_ratio' => 1.0]);
        $res = (new AtlasLoopComprehensionGroundingGate)->groundAgainstInventory('x', [
            'AtlasLoopResearchOriginator', 'GhostA',
        ], $this->inventory());
        $this->assertFalse($res['grounded'], 'at ratio 1.0 a single loose citation vetoes again (fail-closed restored)');
    }
}
