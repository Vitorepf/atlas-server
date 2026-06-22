<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDocGapSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §2 · DOC-GAP — the brain originates a capability the canonical docs DEMAND but no symbol provides: a
 * red-gated feature directive the proxy scan can never surface (it only sees code that exists). The mint is
 * deterministic + provenance-honest; the AUTHORING is model-bound (the cert's red→green is the sole authority).
 */
final class AtlasLoopDocGapSupplyLaneTest extends TestCase
{
    public function test_mints_a_red_gated_capability_directive_per_doc_stated_gap(): void
    {
        $model = $this->model(['AtlasLoopEgressFirewall', 'AtlasLoopEgressFirewall', '  ', 'AtlasLoopBudgetSentinel']);

        $specs = (new AtlasLoopDocGapSupplyLane)->mint($model, sys_get_temp_dir());

        // two DISTINCT non-blank gaps ⇒ two directives (the duplicate + the blank are dropped).
        $this->assertCount(2, $specs);
        $spec = $specs[0];
        $this->assertSame('feature', $spec['payload']['objective_kind'], 'doc-gap originates a feature (red→green)');
        $this->assertTrue($spec['payload']['red_required'], 'the new capability test must be RED first (Guard 4 diff_earned)');
        $this->assertTrue($spec['payload']['comprehension_originated']);
        $this->assertSame('AtlasLoopEgressFirewall', $spec['payload']['capability']);
        $this->assertStringContainsString('AtlasLoopEgressFirewall', $spec['objective']);
        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $spec['payload']['provenance'], 'the doc is named, never trusted as proof');
    }

    public function test_no_doc_gaps_mints_nothing(): void
    {
        $this->assertSame([], (new AtlasLoopDocGapSupplyLane)->mint($this->model([]), sys_get_temp_dir()));
    }

    /**
     * @param  list<string>  $docStatedGaps
     */
    private function model(array $docStatedGaps): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [], edges: [], orphans: [], cloneClusters: [], forbidden: [], docPurposes: [],
            docStatedGaps: $docStatedGaps,
            snapshotId: 'snap',
        );
    }
}
