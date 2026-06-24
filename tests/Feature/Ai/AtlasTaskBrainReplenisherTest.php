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
        }
    }

    public function test_orphans_are_opt_in(): void
    {
        $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model(), includeOrphans: true);

        $orphan = array_values(array_filter($tasks, fn (array $t): bool => str_starts_with($t['task_packet_id'], 'brain-orphan-')));
        $this->assertCount(2, $orphan, 'orphan tasks appear only when explicitly requested');
        $this->assertStringContainsString('Orphan', $orphan[0]['objective']);
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
            ['rel_path' => 'app/Demo/OrphanOne.php', 'fqcn' => 'App\\Demo\\OrphanOne', 'is_orphan' => true, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/OrphanTwo.php', 'fqcn' => 'App\\Demo\\OrphanTwo', 'is_orphan' => true, 'clone_cluster_id' => null],
            ['rel_path' => 'app/Demo/Used.php', 'fqcn' => 'App\\Demo\\Used', 'is_orphan' => false, 'clone_cluster_id' => null],
        ];

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: ['App\\Demo\\OrphanOne', 'App\\Demo\\OrphanTwo'],
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
