<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskBrainReplenisher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * PART 2 — the brain STRUCTURES the task list itself from its comprehension, and keeps the serving queue full.
 * The operator does not describe tasks; the comprehension does (orphans + doc-gaps → real evolution tasks).
 */
final class AtlasTaskBrainReplenisherTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
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
            // fable-refactor c48: property-gated docgap targets ALSO mint constitution evidence.
            $this->assertSame(['tests_or_gates_result', 'constitution_gate_receipt'], $t['required_evidence']);
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

    public function test_doc_gap_packets_carry_prioritization_metadata(): void
    {
        $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model());

        foreach ($tasks as $t) {
            if (! str_starts_with($t['task_packet_id'], 'brain-docgap-')) {
                continue;
            }
            $this->assertArrayHasKey('domain_area', $t);
            $this->assertArrayHasKey('template_family', $t);
            $this->assertArrayHasKey('leverage_factors', $t);
            $this->assertArrayHasKey('prioritization_reason', $t);
            $this->assertIsString($t['domain_area']);
            $this->assertNotEmpty($t['domain_area']);
            $this->assertIsArray($t['leverage_factors']);
            $this->assertNotEmpty($t['leverage_factors']);
            $this->assertNotEmpty($t['template_family']);
            $this->assertNotEmpty($t['prioritization_reason']);
            $this->assertCount(2, $t['allowed_files'], 'metadata must not change allowed_files');
        }

        // Orphan packets must NOT carry domain metadata.
        $withOrphans = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($this->model(), includeOrphans: true);
        $orphan = array_values(array_filter($withOrphans, fn (array $t): bool => str_starts_with($t['task_packet_id'], 'brain-orphan-')));
        $this->assertNotEmpty($orphan);
        $this->assertArrayNotHasKey('domain_area', $orphan[0]);
    }

    public function test_template_family_dedup_emits_only_strongest_per_family(): void
    {
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [
                'a retry budget for provider calls',  // family: budget
                'a performance budget for queue ops', // family: budget (collision → skipped)
                'a circuit breaker for the registry', // family: breaker (unique)
            ],
            snapshotId: 'dedup-test',
        );

        $tasks = (new AtlasTaskBrainReplenisher($this->orchestrator()))->structureTasks($model);

        $this->assertCount(2, $tasks, 'second budget gap is deduped; budget+breaker survive');
        $families = array_column($tasks, 'template_family');
        $this->assertContains('budget', $families);
        $this->assertContains('breaker', $families);
    }

    public function test_skipped_template_family_count_in_replenish_summary(): void
    {
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [],
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [
                'a retry budget for provider calls',
                'a performance budget for queue ops', // same family → skipped
            ],
            snapshotId: 'dedup-count-test',
        );

        $r = (new AtlasTaskBrainReplenisher($this->orchestrator()))
            ->replenishFromModel($model, 'app/Demo', targetMin: 20, maxPerRun: 40);

        $this->assertArrayHasKey('skipped_template_family_count', $r);
        $this->assertSame(1, $r['skipped_template_family_count']);
    }

    public function test_autonomos_proposal_competition_selects_one_quality_complete_candidate_before_enqueue(): void
    {
        $r = (new AtlasTaskBrainReplenisher($this->orchestrator()))
            ->replenishFromModel(
                $this->model(),
                'app/Demo',
                targetMin: 20,
                maxPerRun: 40,
                requireProposalCompetition: true,
            );

        $this->assertSame('proposal_competition_passed', $r['proposal_competition']['status']);
        $this->assertSame(3, $r['proposal_competition']['candidate_count']);
        $this->assertSame(1, $r['enqueued_count']);
        $this->assertSame($r['proposal_competition']['winner_id'], $r['enqueued'][0]);
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
            File::deleteDirectory($repo);
        }
    }

    private function writeClass(string $repo, string $rel, string $namespace, string $class): void
    {
        $path = $repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, "<?php\n\nnamespace {$namespace};\n\nfinal class {$class} {}\n");
    }

    public function test_replenish_fills_the_serving_queue_and_is_idempotent(): void
    {
        $orch = $this->orchestrator();
        $replenisher = new AtlasTaskBrainReplenisher($orch);
        $model = $this->model();

        // First pass: the queue is empty → the brain mints its structured tasks. The 3 doc-gaps
        // share one minting template, so the fable-v3-w1 anti-farm admission SERIALIZES them:
        // exactly one claimable sibling; the other two are counted as blocked, never dropped silently.
        $r1 = $replenisher->replenishFromModel($model, 'app/Demo', targetMin: 20, maxPerRun: 40);
        $this->assertSame(1, $r1['enqueued_count'], 'anti-farm admission lets one template sibling through');
        $this->assertSame(2, $r1['skipped_blocked_admission'], 'farm-blocked candidates are counted');
        $this->assertGreaterThan(0, $this->claimable($orch), 'tasks are now claimable by any AI');

        // Second pass: same comprehension → nothing new (dedup + the claimable sibling still
        // farm-blocks its twins), no duplicate tasks.
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
}
