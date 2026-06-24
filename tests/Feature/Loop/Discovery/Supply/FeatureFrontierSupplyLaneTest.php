<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\FeatureFrontierSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\SupplyLaneContract;
use Tests\TestCase;

/**
 * Proves the FEATURE-FRONTIER supply lane: it originates ONLY material red→green features the scope's docs
 * promise (docPurposes) that neither an inventoried symbol provides NOR a stated doc-gap names. ANTI-GOODHART:
 * never coverage/proxy/refactor — the lane mints a red_required directive and the red→green cert is the
 * authority. Pure over the comprehension model (no provider, no DB).
 */
final class FeatureFrontierSupplyLaneTest extends TestCase
{
    /**
     * @param  array<string,string>  $docPurposes  fqcn => purpose sentence
     * @param  list<string>  $docStatedGaps
     * @param  list<array<string,mixed>>  $inventory
     */
    private function model(array $docPurposes, array $docStatedGaps = [], array $inventory = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: $docPurposes,
            docStatedGaps: $docStatedGaps,
            snapshotId: 'snap-test',
        );
    }

    /** @return array<string,mixed> */
    private function inventoryNode(string $relPath, string $fqcn): array
    {
        return [
            'rel_path' => $relPath,
            'fqcn' => $fqcn,
            'public_methods' => [],
            'is_orphan' => false,
            'is_forbidden' => false,
            'clone_cluster_id' => null,
        ];
    }

    private function lane(): FeatureFrontierSupplyLane
    {
        return new FeatureFrontierSupplyLane;
    }

    // (a) two docPurposes — one already provided by inventory, one frontier → exactly one spec.
    public function test_mints_one_spec_for_the_frontier_capability_and_drops_the_inventoried_one(): void
    {
        $lane = $this->lane();
        $this->assertInstanceOf(SupplyLaneContract::class, $lane, 'lane must implement the supply contract');

        $model = $this->model(
            docPurposes: [
                'App\\Foo\\Existing' => 'provides the existing thing',
                'App\\Bar\\FrontierThing' => 'serializes the audit ledger',
            ],
            docStatedGaps: [],
            inventory: [$this->inventoryNode('app/Foo/Existing.php', 'App\\Foo\\Existing')],
        );

        $specs = $lane->mint($model, '/repo/');

        $this->assertCount(1, $specs);
        $spec = $specs[0];
        $this->assertSame('FrontierThing', $spec['payload']['capability']);
        $this->assertSame('feature_frontier', $spec['payload']['objective_kind']);
        $this->assertSame('feature_frontier', $spec['payload']['source']);
        $this->assertSame('feature_frontier', FeatureFrontierSupplyLane::OBJECTIVE_KIND);
        $this->assertTrue($spec['payload']['red_required']);
        $this->assertTrue($spec['payload']['comprehension_originated']);
        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $spec['payload']['provenance']);
        $this->assertSame([], $spec['members']);
        $this->assertStringContainsString('FrontierThing', $spec['objective']);
        $this->assertStringContainsString('serializes the audit ledger', $spec['objective']);
    }

    // (b) a capability ALSO named in docStatedGaps is owned by the DocGap lane → dropped here.
    public function test_capability_already_in_doc_stated_gaps_is_dropped_no_overlap_with_docgap_lane(): void
    {
        $model = $this->model(
            docPurposes: ['App\\X\\GapThing' => 'a capability the docs also list as a gap'],
            docStatedGaps: ['GapThing'],
            inventory: [],
        );

        $this->assertSame([], $this->lane()->mint($model, '/repo'));
    }

    // (c) empty / whitespace purpose → fail-closed drop.
    public function test_blank_purpose_is_dropped_fail_closed(): void
    {
        $model = $this->model(
            docPurposes: ['App\\X\\Thing' => '   '],
            docStatedGaps: [],
            inventory: [],
        );

        $this->assertSame([], $this->lane()->mint($model, '/repo'));
    }

    // (d) two distinct fqcn keys collapsing to the same capability short-name → one spec (dedup).
    public function test_dedup_by_capability_short_name(): void
    {
        $model = $this->model(
            docPurposes: [
                'App\\A\\Dup' => 'first purpose',
                'App\\B\\Dup' => 'second purpose',
            ],
            docStatedGaps: [],
            inventory: [],
        );

        $specs = $this->lane()->mint($model, '/repo');

        $this->assertCount(1, $specs);
        $this->assertSame('Dup', $specs[0]['payload']['capability']);
    }

    // (e) an empty model originates nothing.
    public function test_empty_model_mints_nothing(): void
    {
        $this->assertSame([], $this->lane()->mint($this->model([]), '/repo'));
    }
}
