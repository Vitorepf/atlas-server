<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §5.6 · LAYER 2 CROSS-MODEL ORIGINATION — the brain ORIGINATES an evolution (writer ≠ judge): a frontier
 * model PROPOSES {objective, cited_symbols}; the DETERMINISTIC inventory judge clears every citation against
 * MEMBERSHIP. A proposal citing a real symbol is originated; one citing a hallucinated symbol is REFUTED no
 * matter how confident the writer; no writer ⇒ no origination (fail-closed). The deterministic judge keeps
 * the empirical writer honest.
 */
final class AtlasLoopComprehensionOriginatorTest extends TestCase
{
    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [
                ['rel_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopResearchOriginator.php', 'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator', 'public_methods' => ['derive'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ],
            edges: [],
            orphans: ['App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopResearchOriginator'],
            cloneClusters: [], forbidden: [], docPurposes: [], docStatedGaps: [],
            snapshotId: 'snap',
        );
    }

    public function test_a_proposal_citing_a_real_inventory_symbol_is_originated(): void
    {
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopResearchOriginator into the discovery feed.', 'cited_symbols' => ['AtlasLoopResearchOriginator']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model());

        $this->assertTrue($res['originated'], 'the writer proposed it AND the inventory judge cleared the citation');
        $this->assertSame([], $res['refuted']);
        $this->assertStringContainsString('AtlasLoopResearchOriginator', (string) $res['objective']);
    }

    public function test_a_proposal_citing_a_hallucinated_symbol_is_refuted_writer_not_judge(): void
    {
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopTotallyMadeUp into the feed.', 'cited_symbols' => ['AtlasLoopTotallyMadeUp']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model());

        $this->assertFalse($res['originated'], 'a hallucinated citation is REFUTED by the deterministic judge');
        $this->assertContains('AtlasLoopTotallyMadeUp', $res['refuted']);
        $this->assertSame('citations_refuted_by_inventory_judge', $res['reason']);
    }

    public function test_majority_anchored_mix_originates_dropping_the_loose_citation(): void
    {
        // ANCHORED, not all-or-nothing: an objective resting on a REAL symbol + one loose citation (a doc, a
        // helper outside the class inventory) is no longer vetoed as hallucinated — it originates on the
        // resolved symbol and DROPS the loose one (kept as provenance, never acted on). This is the strangle
        // the old "any-refuted-kills-it" rule imposed on the material lane.
        $writer = fn (string $p): array => ['objective' => 'Wire AtlasLoopResearchOriginator (per docs/loop-os-architecture.md).', 'cited_symbols' => ['AtlasLoopResearchOriginator', 'docs/loop-os-architecture.md']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model());

        $this->assertTrue($res['originated'], 'a real-symbol-anchored objective with one loose citation still originates');
        $this->assertSame(['AtlasLoopResearchOriginator'], $res['cited_symbols'], 'it proceeds on the RESOLVED symbol only');
        $this->assertContains('docs/loop-os-architecture.md', $res['refuted'], 'the loose citation is surfaced as dropped provenance');
    }

    public function test_minority_resolved_is_still_refuted_as_hallucinated(): void
    {
        // The veto still BITES when the objective is mostly invented: 1 real among 3 fakes (ratio 0.25 < 0.5)
        // is the "1 real symbol smuggling a fabricated objective" tell and is REFUSED.
        $writer = fn (string $p): array => ['objective' => 'x', 'cited_symbols' => ['AtlasLoopResearchOriginator', 'GhostOne', 'GhostTwo', 'GhostThree']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model());

        $this->assertFalse($res['originated'], 'a minority-resolved citation set is still vetoed as likely hallucinated');
        $this->assertSame('citations_refuted_by_inventory_judge', $res['reason']);
    }

    public function test_no_writer_yields_no_origination_fail_closed(): void
    {
        config(['atlas.loop.default_provider' => '']);
        $res = (new AtlasLoopComprehensionOriginator)->originate($this->model());
        $this->assertFalse($res['originated']);
        $this->assertSame('no_proposal', $res['reason']);
    }

    public function test_prior_attempts_inform_the_writer_as_context(): void
    {
        // §5 learning realimenting comprehension: the campaign's non-converged history reaches the writer.
        $seen = '';
        $writer = function (string $p) use (&$seen): array {
            $seen = $p;

            return ['objective' => 'Wire AtlasLoopResearchOriginator.', 'cited_symbols' => ['AtlasLoopResearchOriginator']];
        };
        (new AtlasLoopComprehensionOriginator($writer))->originate($this->model(), ['App\\X\\AlreadyParked']);

        $this->assertStringContainsString('did NOT converge', $seen, 'the writer is told what already failed');
        $this->assertStringContainsString('App\\X\\AlreadyParked', $seen);
    }

    public function test_prior_attempt_is_context_not_a_veto(): void
    {
        // a parked target is NOT suppressed — the writer may re-cite it (with a better design) and it originates.
        $writer = fn (string $p): array => ['objective' => 'Re-approach AtlasLoopResearchOriginator with a better design.', 'cited_symbols' => ['AtlasLoopResearchOriginator']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model(), ['AtlasLoopResearchOriginator']);

        $this->assertTrue($res['originated'], 'the prior-attempt context informs but never vetoes a re-cite');
        $this->assertSame([], $res['refuted']);
    }

    public function test_strict_parse_of_the_writer_marker_format(): void
    {
        $originator = new AtlasLoopComprehensionOriginator;
        $parsed = $originator->parse("<<<OBJECTIVE>>>\nWire X\n<<<CITES>>>\nAtlasLoopResearchOriginator, App\\X\\Y\n<<<END>>>");
        $this->assertSame('Wire X', $parsed['objective']);
        $this->assertSame(['AtlasLoopResearchOriginator', 'App\\X\\Y'], $parsed['cited_symbols']);
        $this->assertNull($originator->parse('no markers'), 'a malformed response yields no proposal');
    }
}
