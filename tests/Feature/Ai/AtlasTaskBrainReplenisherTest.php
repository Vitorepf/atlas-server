<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PART 2 — the brain STRUCTURES the task list itself from its comprehension, and keeps the serving queue full.
 * The operator does not describe tasks; the comprehension does (orphans + doc-gaps → real evolution tasks).
 */
final class AtlasTaskBrainReplenisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_structures_resolvable_doc_gap_tasks_by_default(): void
    {
        $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model());

        // DEFAULT = doc-gaps only — RESOLVABLE in-scope (new class + new test, both in allowed_files). Orphans
        // (multi-file, give_back-prone) are excluded by default. NO proxy/coverage tasks (anti-Goodhart).
        $this->assertCount(3, $tasks, 'one task per doc-gap');
        foreach ($tasks as $t) {
            $this->assertStringStartsWith('brain-docgap-', $t['task_packet_id']);
            // Resolvable: the new class file AND its new test file are BOTH in allowed_files (nothing to wire).
            $this->assertCount(2, $t['allowed_files']);
            $this->assertStringContainsString('tests/', implode(' ', $t['allowed_files']));
            $this->assertSame(['tests_or_gates_result'], $t['required_evidence']);
            $this->assertSame('shared_local_main_with_scope_lock', $t['workspace_policy']['isolation']);
        }
    }

    public function test_orphans_are_opt_in_and_shaped_resolvable(): void
    {
        $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model(), includeOrphans: true);

        $orphan = array_values(array_filter($tasks, fn (array $t): bool => str_starts_with($t['task_packet_id'], 'brain-orphan-')));

        // RESOLVABLE shaping: only an orphan with a GROUNDED integration site becomes a task. AlphaGate (role
        // "gate") inherits the wiring of its analogous wired sibling BetaGate — invoked from Caller.php — so the
        // task carries [orphan, that production caller] and names the sibling. LonelyMeter has no role-sibling
        // and no wired neighbour in its directory → DEFERRED (never emitted as give-back-bait).
        $this->assertCount(1, $orphan, 'only orphans with a grounded wiring site are emitted; siteless ones defer');
        $t = $orphan[0];
        $this->assertContains('app/Demo/AlphaGate.php', $t['allowed_files']);
        $this->assertContains('app/Demo/Caller.php', $t['allowed_files'], 'the wiring site = the analogous sibling’s production caller');
        $this->assertStringContainsString('BetaGate', $t['objective'], 'the task points to the analogous already-wired sibling');
        $this->assertStringContainsString('Wire', $t['objective']);

        // And it is opt-in: the default (doc-gaps only) emits zero orphan tasks.
        $defaultTasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model());
        $this->assertSame([], array_values(array_filter($defaultTasks, fn (array $x): bool => str_starts_with($x['task_packet_id'], 'brain-orphan-'))));
    }

    public function test_doc_gap_is_skipped_when_the_capability_already_exists_anywhere_in_the_repo(): void
    {
        // THE LIVE FALSE-POSITIVE the worker kept giving back: the comprehension scrapes a class name from docs
        // that is a "gap" only relative to the NARROW scope — but the class exists elsewhere in the repo, either
        // OUTSIDE the scope dir or under a FULLER name. Minting those yields nothing but give-backs. A repo-wide
        // token-subset oracle must skip them while keeping genuinely-missing capabilities.
        $repo = sys_get_temp_dir().'/atlas-docgap-repo-'.bin2hex(random_bytes(5));
        $this->writeClass($repo, 'app/Models/AtlasLoopProposal.php', 'App\\Models', 'AtlasLoopProposal');                 // exists OUTSIDE the scope
        $this->writeClass($repo, 'app/Services/X/AtlasUnifiedLoopOrchestrator.php', 'App\\Services\\X', 'AtlasUnifiedLoopOrchestrator'); // exists under a FULLER name

        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [
                'AtlasLoopProposal',                       // out-of-scope existing → must be skipped
                'AtlasLoopOrchestrator',                   // concept exists as AtlasUnifiedLoopOrchestrator → skipped
                'AtlasLoopGenuinelyMissingCapabilityXyz',  // nothing matches → KEPT (a real task)
            ],
            snapshotId: 'docgap-filter',
        );

        try {
            $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator(), null, null, $repo))->structureTasks($model);

            $this->assertCount(1, $tasks, 'both already-existing capabilities are filtered; only the genuine gap survives');
            $this->assertStringContainsString('AtlasLoopGenuinelyMissingCapabilityXyz', (string) $tasks[0]['objective']);
        } finally {
            $this->rmrf($repo);
        }
    }

    private function writeClass(string $repo, string $rel, string $namespace, string $class): void
    {
        $path = $repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, "<?php\n\nnamespace {$namespace};\n\nfinal class {$class} {}\n");
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $p = $dir.'/'.$i;
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public function test_replenish_fills_the_serving_queue_and_is_idempotent(): void
    {
        $orch = $this->orchestrator();
        $replenisher = new AtlasTaskBrainReplenisher($orch);
        $model = $this->model();

        // First pass: the queue is empty → the brain mints its structured tasks.
        $r1 = $replenisher->replenishFromModel($model, 'app/Demo', targetMin: 20, maxPerRun: 40);
        $this->assertGreaterThanOrEqual(3, $r1['enqueued_count'], 'the brain filled the queue from comprehension');
        $this->assertGreaterThan(0, $this->claimable($orch), 'tasks are now claimable by any AI');

        // Second pass: same comprehension → nothing new (dedup), no duplicate tasks.
        $r2 = $replenisher->replenishFromModel($model, 'app/Demo', targetMin: 20, maxPerRun: 40);
        $this->assertSame(0, $r2['enqueued_count'], 'a re-run mints nothing already in the queue (idempotent)');
        $this->assertSame($r1['enqueued_count'], $r2['skipped_existing']);
    }

    public function test_respects_the_watermark(): void
    {
        $replenisher = new AtlasTaskBrainReplenisher($this->orchestrator());
        // target=1 → it stops after the queue reaches 1 claimable, even though 3 candidates exist.
        $r = $replenisher->replenishFromModel($this->model(), 'app/Demo', targetMin: 1, maxPerRun: 40);
        $this->assertSame(1, $r['enqueued_count'], 'stops at the watermark, never over-mints');
    }

    private function claimable(AgentControlPlaneTaskQueueOrchestrator $orch): int
    {
        return count((new AgentControlPlaneTaskPacketQueueRepository)->list(['status' => 'claimable']));
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        $inventory = [
            // AlphaGate is an orphan; BetaGate is the analogous WIRED sibling (same "gate" role + namespace),
            // invoked from Caller.php → AlphaGate's grounded wiring site is Caller.php.
            ['rel_path' => 'app/Demo/AlphaGate.php', 'fqcn' => 'App\\Demo\\AlphaGate', 'public_methods' => ['allows'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/BetaGate.php', 'fqcn' => 'App\\Demo\\BetaGate', 'public_methods' => ['allows'], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/Caller.php', 'fqcn' => 'App\\Demo\\Caller', 'public_methods' => [], 'is_orphan' => false, 'is_forbidden' => false, 'clone_cluster_id' => null],
            // LonelyMeter is an orphan with no role-sibling and no wired neighbour in its directory → DEFERRED.
            ['rel_path' => 'app/Other/LonelyMeter.php', 'fqcn' => 'App\\Other\\LonelyMeter', 'public_methods' => ['measure'], 'is_orphan' => true, 'is_forbidden' => false, 'clone_cluster_id' => null],
        ];

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: ['app/Demo/BetaGate.php' => ['app/Demo/Caller.php']],
            orphans: ['App\\Demo\\AlphaGate', 'App\\Other\\LonelyMeter'],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [
                'a durable retry budget for provider calls',
                'a circuit breaker for the lease registry',
                'an append-only audit of every served packet',
            ],
            snapshotId: 'test-snap',
        );
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }
}
