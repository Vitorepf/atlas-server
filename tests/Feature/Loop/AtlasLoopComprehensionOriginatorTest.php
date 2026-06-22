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

    public function test_any_refuted_citation_in_a_mix_kills_the_origination(): void
    {
        $writer = fn (string $p): array => ['objective' => 'x', 'cited_symbols' => ['AtlasLoopResearchOriginator', 'AtlasLoopGhost']];
        $res = (new AtlasLoopComprehensionOriginator($writer))->originate($this->model());

        $this->assertFalse($res['originated'], 'one hallucinated citation refutes the whole proposal');
        $this->assertContains('AtlasLoopGhost', $res['refuted']);
    }

    public function test_no_writer_yields_no_origination_fail_closed(): void
    {
        config(['atlas.loop.default_provider' => '']);
        $res = (new AtlasLoopComprehensionOriginator)->originate($this->model());
        $this->assertFalse($res['originated']);
        $this->assertSame('no_proposal', $res['reason']);
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
