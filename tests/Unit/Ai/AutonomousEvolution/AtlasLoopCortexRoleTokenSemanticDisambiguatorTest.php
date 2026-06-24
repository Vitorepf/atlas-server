<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCortexRoleTokenSemanticDisambiguator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use Tests\TestCase;

/**
 * Proves the role-token semantic disambiguator: it REJECTS a role-token-only false twin (TransferGate vs
 * ResourceGate) and ACCEPTS a true sibling sharing 2+ signals; and that AtlasTaskBrainReplenisher's
 * inferIntegrationSites() consults it so a false-twin orphan defers instead of being wired to the wrong site.
 */
final class AtlasLoopCortexRoleTokenSemanticDisambiguatorTest extends TestCase
{
    private function disambiguator(): AtlasLoopCortexRoleTokenSemanticDisambiguator
    {
        return new AtlasLoopCortexRoleTokenSemanticDisambiguator;
    }

    public function test_rejects_role_token_only_false_twin(): void
    {
        $accepts = $this->disambiguator()->accepts(
            ['fqcn' => 'App\\Pay\\TransferGate', 'docblock' => 'transfers funds between accounts', 'subsystem_root' => 'app/Pay'],
            ['fqcn' => 'App\\Quota\\ResourceGate', 'docblock' => 'allocates resource quota pools', 'subsystem_root' => 'app/Quota', 'caller_subsystem_roots' => ['app/Quota']],
        );

        $this->assertFalse($accepts, 'shared role token only ⇒ not a sibling');
    }

    public function test_accepts_true_sibling_sharing_two_signals(): void
    {
        $accepts = $this->disambiguator()->accepts(
            ['fqcn' => 'App\\Pay\\TransferGate', 'docblock' => 'transfer funds between accounts', 'subsystem_root' => 'app/Pay'],
            ['fqcn' => 'App\\Pay\\TransferAuditGate', 'docblock' => 'audit every funds transfer', 'subsystem_root' => 'app/Pay', 'caller_subsystem_roots' => ['app/Pay']],
        );

        $this->assertTrue($accepts, 'shared docblock token + same namespace + caller-root overlap ⇒ sibling');
    }

    public function test_replenisher_defers_false_twin_but_emits_true_sibling(): void
    {
        $inventory = [
            // TRUE sibling: AlphaGate (orphan) ↔ BetaGate (wired via Caller, same App\Demo + same dir).
            ['rel_path' => 'app/Demo/AlphaGate.php', 'fqcn' => 'App\\Demo\\AlphaGate', 'public_methods' => ['allows'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/BetaGate.php', 'fqcn' => 'App\\Demo\\BetaGate', 'public_methods' => ['allows'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/Caller.php', 'fqcn' => 'App\\Demo\\Caller', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            // FALSE twin: TransferGate (orphan, App\Pay) whose only role-'gate' match is ResourceGate (App\Quota,
            // callers in app/Quota) — different namespace AND different caller subsystem ⇒ disambiguator rejects.
            ['rel_path' => 'app/Pay/TransferGate.php', 'fqcn' => 'App\\Pay\\TransferGate', 'public_methods' => ['charge'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Quota/ResourceGate.php', 'fqcn' => 'App\\Quota\\ResourceGate', 'public_methods' => ['allows'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Quota/QuotaCaller.php', 'fqcn' => 'App\\Quota\\QuotaCaller', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
        ];
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: ['app/Demo/BetaGate.php' => ['app/Demo/Caller.php'], 'app/Quota/ResourceGate.php' => ['app/Quota/QuotaCaller.php']],
            orphans: ['App\\Demo\\AlphaGate', 'App\\Pay\\TransferGate'],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'disambig-snap',
        );

        $packets = (new AtlasTaskBrainReplenisher(app(AgentControlPlaneTaskQueueOrchestrator::class)))
            ->structureTasks($model, includeOrphans: true);

        $ids = array_map(static fn (array $p): string => (string) $p['task_packet_id'], $packets);
        $alphaId = 'brain-orphan-'.substr(md5('App\\Demo\\AlphaGate'), 0, 12);
        $transferId = 'brain-orphan-'.substr(md5('App\\Pay\\TransferGate'), 0, 12);

        $this->assertContains($alphaId, $ids, 'the true sibling orphan is emitted');
        $this->assertNotContains($transferId, $ids, 'the false-twin orphan defers (never wired to the wrong site)');
    }
}
