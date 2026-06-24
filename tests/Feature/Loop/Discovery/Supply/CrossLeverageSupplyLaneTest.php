<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\CrossLeverageSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\SupplyLaneContract;
use Tests\TestCase;

final class CrossLeverageSupplyLaneTest extends TestCase
{
    public function test_model_with_orphan_in_same_subdir_as_two_member_cluster_mints_one_spec(): void
    {
        $specs = (new CrossLeverageSupplyLane)->mint($this->model(
            inventory: [
                $this->node('app/Domain/Orphan.php', 'App\\Domain\\Orphan', isOrphan: true),
                $this->node('app/Domain/CloneA.php', 'App\\Domain\\CloneA'),
                $this->node('app/Domain/CloneB.php', 'App\\Domain\\CloneB'),
            ],
            orphans: ['App\\Domain\\Orphan'],
            cloneClusters: [
                $this->cluster('cluster-domain', ['app/Domain/CloneA.php', 'app/Domain/CloneB.php']),
            ],
        ), '/unused');

        $this->assertCount(1, $specs);
        $this->assertInstanceOf(SupplyLaneContract::class, new CrossLeverageSupplyLane);
        $this->assertSame(CrossLeverageSupplyLane::OBJECTIVE_KIND, $specs[0]['payload']['objective_kind']);
        $this->assertSame('cross_leverage', $specs[0]['payload']['source']);
        $this->assertSame('App\\Domain\\Orphan', $specs[0]['payload']['orphan_fqcn']);
        $this->assertSame('app/Domain/Orphan.php', $specs[0]['payload']['orphan_path']);
        $this->assertSame('cluster-domain', $specs[0]['payload']['clone_cluster_id']);
        $this->assertSame(['app/Domain/CloneA.php', 'app/Domain/CloneB.php'], $specs[0]['payload']['clone_members']);
        $this->assertTrue($specs[0]['payload']['comprehension_originated']);
        $this->assertTrue($specs[0]['payload']['red_required']);
        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $specs[0]['payload']['provenance']);
        $this->assertSame(['app/Domain/Orphan.php', 'app/Domain/CloneA.php', 'app/Domain/CloneB.php'], $specs[0]['members']);
        $this->assertStringContainsString('Co-evolve App\\Domain\\Orphan', $specs[0]['objective']);
    }

    public function test_cluster_reduced_below_two_members_after_forbidden_filter_is_dropped(): void
    {
        $specs = (new CrossLeverageSupplyLane)->mint($this->model(
            inventory: [
                $this->node('app/Domain/Orphan.php', 'App\\Domain\\Orphan', isOrphan: true),
            ],
            orphans: ['App\\Domain\\Orphan'],
            cloneClusters: [
                $this->cluster('cluster-forbidden', ['app/Domain/CloneA.php', 'app/Domain/CloneB.php']),
            ],
            forbidden: ['app/Domain/CloneA.php'],
        ), '/unused');

        $this->assertSame([], $specs);
    }

    public function test_orphan_in_different_subdir_from_cluster_mints_no_specs(): void
    {
        $specs = (new CrossLeverageSupplyLane)->mint($this->model(
            inventory: [
                $this->node('app/Other/Orphan.php', 'App\\Other\\Orphan', isOrphan: true),
            ],
            orphans: ['App\\Other\\Orphan'],
            cloneClusters: [
                $this->cluster('cluster-domain', ['app/Domain/CloneA.php', 'app/Domain/CloneB.php']),
            ],
        ), '/unused');

        $this->assertSame([], $specs);
    }

    public function test_empty_model_returns_empty_specs(): void
    {
        $this->assertSame([], (new CrossLeverageSupplyLane)->mint($this->model(), '/unused'));
    }

    public function test_output_order_is_deterministic_by_orphan_path(): void
    {
        $specs = (new CrossLeverageSupplyLane)->mint($this->model(
            inventory: [
                $this->node('app/Domain/ZetaOrphan.php', 'App\\Domain\\ZetaOrphan', isOrphan: true),
                $this->node('app/Domain/CloneB.php', 'App\\Domain\\CloneB'),
                $this->node('app/Domain/AlphaOrphan.php', 'App\\Domain\\AlphaOrphan', isOrphan: true),
                $this->node('app/Domain/CloneA.php', 'App\\Domain\\CloneA'),
            ],
            orphans: ['App\\Domain\\ZetaOrphan', 'App\\Domain\\AlphaOrphan'],
            cloneClusters: [
                $this->cluster('cluster-domain', ['app/Domain/CloneB.php', 'app/Domain/CloneA.php']),
            ],
        ), '/unused');

        $this->assertSame([
            'app/Domain/AlphaOrphan.php',
            'app/Domain/ZetaOrphan.php',
        ], array_map(static fn (array $spec): string => (string) $spec['payload']['orphan_path'], $specs));
        $this->assertSame([
            ['app/Domain/AlphaOrphan.php', 'app/Domain/CloneA.php', 'app/Domain/CloneB.php'],
            ['app/Domain/ZetaOrphan.php', 'app/Domain/CloneA.php', 'app/Domain/CloneB.php'],
        ], array_column($specs, 'members'));
    }

    /**
     * @param  list<array<string, mixed>>  $inventory
     * @param  list<string>  $orphans
     * @param  list<array<string, mixed>>  $cloneClusters
     * @param  list<string>  $forbidden
     */
    private function model(array $inventory = [], array $orphans = [], array $cloneClusters = [], array $forbidden = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: $orphans,
            cloneClusters: $cloneClusters,
            forbidden: $forbidden,
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'test-snapshot',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $path, string $fqcn, bool $isOrphan = false, bool $isForbidden = false): array
    {
        return [
            'rel_path' => $path,
            'fqcn' => $fqcn,
            'public_methods' => ['handle'],
            'is_orphan' => $isOrphan,
            'is_forbidden' => $isForbidden,
            'clone_cluster_id' => null,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function cluster(string $id, array $paths): array
    {
        return [
            'cluster_id' => $id,
            'clone_hash' => 'hash-'.$id,
            'members' => array_map(
                static fn (string $path): array => ['path' => $path, 'symbol' => basename($path, '.php')],
                $paths,
            ),
        ];
    }
}
