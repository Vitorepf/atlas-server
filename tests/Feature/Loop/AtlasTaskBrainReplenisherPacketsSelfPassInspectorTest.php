<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use DomainException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression sentinel: every packet AtlasTaskBrainReplenisher mints must self-pass AtlasTaskPacketQualityInspector
 * across all emit branches (doc-gaps, resolvable orphans, orphans-OFF), and the in-class fail-closed guard must
 * throw if any branch ever drops the mirrored tests/ path while still requiring tests_or_gates_result.
 */
final class AtlasTaskBrainReplenisherPacketsSelfPassInspectorTest extends TestCase
{
    private function replenisher(): AtlasTaskBrainReplenisher
    {
        return new AtlasTaskBrainReplenisher(app(AgentControlPlaneTaskQueueOrchestrator::class));
    }

    private function inspector(): AtlasTaskPacketQualityInspector
    {
        return new AtlasTaskPacketQualityInspector;
    }

    /** Fixture with a clone-cluster item, a resolvable orphan (AlphaGate→wired sibling BetaGate via Caller), and doc-gaps. */
    private function model(): AtlasLoopScopeComprehensionModel
    {
        $inventory = [
            ['rel_path' => 'app/Demo/AlphaGate.php', 'fqcn' => 'App\\Demo\\AlphaGate', 'public_methods' => ['allows'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/BetaGate.php', 'fqcn' => 'App\\Demo\\BetaGate', 'public_methods' => ['allows'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/Caller.php', 'fqcn' => 'App\\Demo\\Caller', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            // a clone-cluster member item (exercises the clone-aware inventory paths).
            ['rel_path' => 'app/Demo/DupOne.php', 'fqcn' => 'App\\Demo\\DupOne', 'public_methods' => ['run'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => 'c1'],
        ];

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: ['app/Demo/BetaGate.php' => ['app/Demo/Caller.php']],
            orphans: ['App\\Demo\\AlphaGate'],
            cloneClusters: [['cluster_id' => 'c1', 'member_paths' => ['app/Demo/DupOne.php', 'app/Demo/DupTwo.php']]],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: ['a durable retry budget for provider calls', 'an append-only audit of every served packet'],
            snapshotId: 'self-pass-snap',
        );
    }

    public function test_every_emitted_packet_self_passes_inspector_with_orphans_on(): void
    {
        $packets = $this->replenisher()->structureTasks($this->model(), includeOrphans: true);

        $this->assertNotEmpty($packets);
        foreach ($packets as $packet) {
            $verdict = $this->inspector()->inspect($packet);
            $this->assertSame([], $verdict['blocking_deficiencies'], 'packet '.($packet['task_packet_id'] ?? '?').' must self-pass the inspector');
        }

        // The resolvable orphan branch genuinely emitted (more packets than the doc-gaps-only run).
        $withoutOrphans = $this->replenisher()->structureTasks($this->model(), includeOrphans: false);
        $this->assertGreaterThan(count($withoutOrphans), count($packets), 'the resolvable orphan was emitted');
    }

    public function test_orphans_off_branch_also_self_passes(): void
    {
        $packets = $this->replenisher()->structureTasks($this->model(), includeOrphans: false);

        $this->assertNotEmpty($packets);
        foreach ($packets as $packet) {
            $this->assertSame([], $this->inspector()->inspect($packet)['blocking_deficiencies']);
        }
    }

    public function test_guard_is_fail_closed_on_a_bad_packet(): void
    {
        $bad = [
            'task_packet_id' => 'mutated-orphan',
            'objective' => 'wire AlphaGate the way BetaGate is wired',
            'allowed_files' => ['app/Demo/AlphaGate.php'], // mirrored tests/ path DROPPED
            'acceptance_criteria' => ['create a passing PHPUnit test exercising AlphaGate'],
            'required_evidence' => ['tests_or_gates_result'],
        ];

        $guard = new ReflectionMethod(AtlasTaskBrainReplenisher::class, 'validateAgainstInspector');
        $guard->setAccessible(true);

        $this->expectException(DomainException::class);
        $guard->invoke($this->replenisher(), $bad);
    }
}
