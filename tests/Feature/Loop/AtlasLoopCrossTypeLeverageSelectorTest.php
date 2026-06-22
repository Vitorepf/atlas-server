<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossTypeLeverageSelector;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageSelector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * §3 · CROSS-TYPE LEVERAGE — the brain picks the highest-leverage step ACROSS work types (wire this orphan
 * vs unify that clone vs originate that doc-gap), not just within one lane. The model picks among the REAL
 * grounded candidates of ALL types; armed it lands the cross-type pick first, off it keeps the producer's
 * deterministic order. Fabrication-proof (it can only reorder the real set).
 */
final class AtlasLoopCrossTypeLeverageSelectorTest extends TestCase
{
    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [
                ['rel_path' => 'app/X/Orphan.php', 'fqcn' => 'App\\X\\Orphan', 'public_methods' => ['go'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
                ['rel_path' => 'app/X/CloneA.php', 'fqcn' => 'App\\X\\CloneA', 'public_methods' => ['calc'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => 'c1'],
                ['rel_path' => 'app/X/CloneB.php', 'fqcn' => 'App\\X\\CloneB', 'public_methods' => ['calc'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => 'c1'],
            ],
            edges: [],
            orphans: ['App\\X\\Orphan'],
            cloneClusters: [['cluster_id' => 'c1', 'clone_hash' => 'h', 'members' => [['path' => 'app/X/CloneA.php', 'symbol' => 'CloneA'], ['path' => 'app/X/CloneB.php', 'symbol' => 'CloneB']]]],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: ['AtlasLoopMissingCapability'],
            snapshotId: 'snap',
        );
    }

    public function test_armed_it_lands_the_cross_type_pick_first(): void
    {
        config()->set('atlas.loop.leverage_selection_enabled', true);
        // the producer emits [orphan_wiring, clone_unification, doc_gap_capability]; the model prefers #2.
        $selector = new AtlasLoopLeverageSelector(fn (string $p, string $pr): string => "<<<PICK>>>\n2");
        $ranked = (new AtlasLoopCrossTypeLeverageSelector($selector))->rankedForModel($this->model());

        $this->assertCount(3, $ranked, 'all three comprehension types are candidates');
        $this->assertSame('doc_gap_capability', $ranked[0]['kind'], 'the model-preferred cross-type step lands first');
    }

    public function test_off_keeps_the_producer_deterministic_order(): void
    {
        config()->set('atlas.loop.leverage_selection_enabled', false);
        $ranked = (new AtlasLoopCrossTypeLeverageSelector)->rankedForModel($this->model());
        $this->assertSame('orphan_wiring', $ranked[0]['kind'], 'off ⇒ the producer order (orphans first), no provider call');
    }
}
