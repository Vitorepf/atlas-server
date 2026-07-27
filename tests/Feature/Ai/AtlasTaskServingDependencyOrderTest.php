<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * PART 2 · ORDER — the version-ladder needs ORDERED delivery: a task is only servable once every depends_on
 * task is COMPLETED. A worker that finds only dependency-gated work must WAIT (the ladder is still advancing),
 * not stop as if the queue were drained. Missing, cancelled, or cyclic prerequisites are fail-closed: they
 * require repair instead of silently authorizing work without a valid dependency order.
 */
final class AtlasTaskServingDependencyOrderTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-order-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    public function test_a_task_waits_until_its_prerequisite_is_completed(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('wave1-A', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('wave2-B', ['wave1-A'], 2)]);
        $serving = new AtlasTaskServingService($orch);

        // A worker pulls → gets the wave-1 task; NEVER the wave-2 task whose prerequisite is unfinished.
        $a = $serving->next('w1');
        $this->assertSame('served', $a['status']);
        $this->assertSame('wave1-A', $a['task']['task_packet_id'], 'order: the prerequisite is served first');

        // Another worker pulls → the only remaining task is gated by A → WAIT (not drained, not served).
        $b = $serving->next('w2');
        $this->assertSame('waiting_on_dependencies', $b['status'], 'a gated task makes the worker wait, not stop');

        // A completes → B opens for serving.
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('wave1-A', 'completed_dry_run', ['agent_id' => 'w1']);
        $b2 = $serving->next('w2');
        $this->assertSame('served', $b2['status'], 'once the prerequisite is done, the dependent task is servable');
        $this->assertSame('wave2-B', $b2['task']['task_packet_id']);
    }

    public function test_an_unknown_dependency_is_not_served_without_a_valid_prerequisite(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('solo', ['this-dep-was-never-enqueued'], 1)]);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('no_claimable_task', $r['status'], 'a missing prerequisite must block serving until an operator repairs the dependency graph');
    }

    public function test_a_cancelled_prerequisite_is_not_served_as_completion(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('prereq-X', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('dependent-Y', ['prereq-X'], 2)]);
        // Cancellation does not prove the prerequisite's work completed.
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('prereq-X', 'cancelled', ['reason' => 'operator_cancelled']);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('no_claimable_task', $r['status'], 'a cancelled prerequisite must not authorize its dependent task');
    }

    public function test_a_cyclic_dependency_is_not_served_without_a_valid_order(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('cycle-A', ['cycle-B'], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('cycle-B', ['cycle-A'], 1)]);
        $serving = new AtlasTaskServingService($orch);

        // A↔B mutually depend — there is NO valid topological order, so neither task can be safely served.
        $first = $serving->next('w1');
        $this->assertSame('no_claimable_task', $first['status'], 'a dependency cycle must not authorize either task');
        $second = $serving->next('w2');
        $this->assertSame('no_claimable_task', $second['status']);
    }

    public function test_a_task_gated_only_by_a_dead_prerequisite_does_not_report_waiting(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('dead-prereq', [], 1)]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('orphan-dependent', ['dead-prereq'], 2)]);
        // The prerequisite is QUARANTINED (blocked) — operator-recoverable, but NOT the advancing ladder. Its
        // dependent must not be told "just wait" on something that will never complete on its own; it falls
        // through to an honest empty (no_claimable_task), NOT a false waiting_on_dependencies.
        (new AgentControlPlaneTaskPacketQueueRepository)->updateStatus('dead-prereq', 'blocked', ['reason' => 'quarantined']);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('no_claimable_task', $r['status'], 'gated ONLY by a dead prereq ⇒ honest empty, never a false waiting_on_dependencies');
    }

    public function test_a_certification_probe_is_never_served_to_a_real_worker(): void
    {
        $orch = $this->orchestrator();
        // A well-formed packet that is nonetheless a certification probe (probe_* id) must never be claimed.
        $orch->prepareAndEnqueue(['task_packet' => $this->input('probe_certification_xyz', [], 1)]);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('no_claimable_task', $r['status'], 'a probe_* packet is never served to a real worker (queue-poisoning guard)');
    }

    public function test_every_probe_family_tag_is_excluded_from_serving(): void
    {
        // The dominant probe families carry a TAG (not a probe_ id) — the certification/fleet/bootstrap machinery
        // stamps these. All must be excluded, even on the shared disk where they could leak in.
        $orch = $this->orchestrator();
        $families = [
            'multi_agent_loop_certification',
            'terminal_worker_bootstrap_probe',
            'terminal_fleet_launch_plan_probe',
            'terminal_fleet_partial_supply_probe',
            'terminal_bootstrap_partial_supply', // carries neither "probe" nor "certification" — prefix-matched
            'terminal_bootstrap_probe',
        ];
        foreach ($families as $i => $tag) {
            $orch->prepareAndEnqueue(['task_packet' => $this->input('certseed-'.$i, [], 1), 'queue' => ['tags' => [$tag]]]);
        }
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('no_claimable_task', $r['status'], 'every certification/fleet/bootstrap probe family is excluded from serving by tag');
    }

    public function test_a_real_tagged_task_is_served_while_probes_are_excluded(): void
    {
        // No false-positive: a real task with benign tags (brain-originated/docs/guardrail) is served even though
        // a probe sits beside it. The probe is skipped; the real task flows.
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => $this->input('a-probe-seed', [], 1), 'queue' => ['tags' => ['terminal_fleet_partial_supply_probe']]]);
        $orch->prepareAndEnqueue(['task_packet' => $this->input('real-brain-task', [], 1), 'queue' => ['tags' => ['brain-originated', 'docs', 'guardrail']]]);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertSame('served', $r['status']);
        $this->assertSame('real-brain-task', $r['task']['task_packet_id'], 'a real brain-originated task is served; the probe beside it is skipped (no false-positive)');
    }

    public function test_a_packet_scoped_to_a_petreo_file_is_never_served_to_a_worker(): void
    {
        // A packet whose allowed_files include config/atlas.php (pétreo) is uncommittable — the scoped committer
        // refuses it. It must never reach a worker (else: implement → test green → commit refused → wasted work,
        // the loop-cortex-memory-cli-1020 case). The quality inspector rejects it before serving.
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'doomed-petreo',
            'objective' => 'register a CLI and tweak the master config',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Console/Commands/AtlasDoomed.php', 'config/atlas.php', 'tests/Unit/Ai/SelfConstruction/Generated/AtlasDoomedTest.php'],
            'scope_in' => ['app/Console/Commands/AtlasDoomed.php', 'config/atlas.php', 'tests/Unit/Ai/SelfConstruction/Generated/AtlasDoomedTest.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['tests_or_gates_result'],
        ]]);
        $serving = new AtlasTaskServingService($orch);

        $r = $serving->next('w1');
        $this->assertNotSame('served', $r['status'], 'a packet scoped to a pétreo file is never handed to a worker (no wasted implementation)');
    }

    /** @param list<string> $dependsOn @return array<string, mixed> */
    private function input(string $id, array $dependsOn = [], int $wave = 0): array
    {
        // Self-sufficient by construction: a packet that requires `tests_or_gates_result` MUST grant a test
        // path in allowed_files (else the quality inspector quarantines it as unprovable). These fixtures
        // exercise dependency ORDERING, so they must be well-formed packets, not malformed ones.
        // Distinct objective STRUCTURE per id: twin "ordered task N" objectives are
        // template-farm-blocked at admission since fable-v3-w1, and these fixtures
        // need several packets claimable at once. Deterministic per id (never flaky).
        $verbs = ['harden', 'paginate', 'instrument', 'migrate', 'cache', 'validate', 'deduplicate', 'refactor'];
        $nouns = ['lease registry', 'packet listing', 'queue reaper', 'evidence ledger', 'scope guard', 'claim matcher', 'receipt store', 'continuation builder'];
        $seed = crc32($id);

        return [
            'task_packet_id' => $id,
            'objective' => sprintf('%s the %s for %s', $verbs[$seed % 8], $nouns[($seed >> 3) % 8], $id),
            'operator_id' => 'tester',
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Generated/'.$id.'.php',
                'tests/Unit/Ai/SelfConstruction/Generated/'.$id.'Test.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Generated/'.$id.'.php',
                'tests/Unit/Ai/SelfConstruction/Generated/'.$id.'Test.php',
            ],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['tests_or_gates_result'],
            'depends_on' => $dependsOn,
            'wave' => $wave,
        ];
    }
}
