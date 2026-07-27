<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * PART 2 — the operator's manual front door (atlas:task:enqueue) + the dedicated serving switch.
 */
final class AtlasTaskEnqueueAndSwitchTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-enq-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        parent::tearDown();
    }

    public function test_enqueue_a_well_specified_task_then_serve_it(): void
    {
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'wire the FooBar into the registry',
            '--allow' => ['app/Services/Foo/Bar.php'],
            '--accept' => ['the FooBar resolves from the container'],
            '--evidence' => ['tests_or_gates_result'],
            '--id' => 'op-demo-1',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit, 'a well-specified task enqueues');

        // The container-resolved serving surface (same faked disk) now serves it.
        $serving = new AtlasTaskServingService($this->orchestrator());
        $res = $serving->next('some-ai');
        $this->assertSame('served', $res['status']);
        $this->assertSame('op-demo-1', $res['task']['task_packet_id']);
        $this->assertSame(['app/Services/Foo/Bar.php'], $res['task']['allowed_files']);
    }

    public function test_enqueue_rejects_an_underspecified_task(): void
    {
        // No acceptance, no evidence ⇒ a cold AI could not implement/prove it ⇒ rejected at the door.
        $exit = Artisan::call('atlas:task:enqueue', [
            '--objective' => 'do something vague',
            '--allow' => ['app/Services/Foo/Bar.php'],
            '--json' => true,
        ]);
        $out = Artisan::output();

        $this->assertSame(1, $exit, 'an underspecified task is rejected');
        $this->assertStringContainsString('not_self_sufficient', $out);
        $this->assertStringContainsString('missing_acceptance_criteria', $out);
    }

    public function test_serving_switch_independently_gates_next(): void
    {
        // Decouple from the master switch to prove the dedicated flag governs serving.
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");
        $servingEnv = sys_get_temp_dir().'/atlas-srv-'.bin2hex(random_bytes(5)).'.env';
        AtlasTaskServingSwitch::$envPathOverride = $servingEnv;

        // Off by default (fail-closed): serving is disabled.
        file_put_contents($servingEnv, "ATLAS_TASK_SERVING_ENABLED=false\n");
        $serving = new AtlasTaskServingService($this->orchestrator());
        $this->assertSame('disabled', $serving->next('ai')['status']);

        // Operator turns serving ON (without the autonomous master switch).
        AtlasTaskServingSwitch::on();
        $this->assertTrue(AtlasTaskServingSwitch::enabled());
        $this->assertSame('no_claimable_task', $serving->next('ai')['status'], 'serving is live (empty queue is honest)');

        @unlink($servingEnv);
    }

    // ── AgentControlPlaneTaskPacketBuilder hard value contract (opt-in) ──────────

    private function hardValuePayload(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'wire the FooBar into the registry',
            'allowed_files' => ['app/Services/Foo/Bar.php', 'tests/Unit/Services/Foo/BarTest.php'],
            'acceptance_criteria' => ['php artisan test --filter=BarTest passes'],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'structural_value_rationale' => 'closes a real capability gap',
            'require_hard_value_contract' => true,
        ], $overrides);
    }

    public function test_hard_value_contract_blocks_packet_missing_implementation_file(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload([
            'allowed_files' => ['tests/Unit/Services/Foo/BarTest.php'],
        ]));

        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('missing_implementation_file', $packet['blocking_reasons']);
    }

    public function test_hard_value_contract_blocks_packet_missing_test_file_and_runnable_proof(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload([
            'allowed_files' => ['app/Services/Foo/Bar.php'],
            'acceptance_criteria' => ['the FooBar resolves from the container'],
        ]));

        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('missing_runnable_test_file', $packet['blocking_reasons']);
    }

    public function test_hard_value_contract_blocks_packet_missing_required_evidence(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload([
            'required_evidence' => [],
        ]));

        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('missing_required_evidence', $packet['blocking_reasons']);
    }

    public function test_hard_value_contract_blocks_packet_missing_structural_value_rationale(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload([
            'structural_value_rationale' => '',
        ]));

        $this->assertSame('blocked', $packet['status']);
        $this->assertContains('missing_structural_value_rationale', $packet['blocking_reasons']);
    }

    public function test_hard_value_contract_admits_a_fully_specified_packet(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload());

        $this->assertSame('planned', $packet['status']);
        $this->assertSame([], $packet['blocking_reasons']);
    }

    public function test_default_packet_keeps_atlas_native_simplicity_contract_without_operator_dependency(): void
    {
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->hardValuePayload());

        $this->assertSame('atlas_native', $packet['simplicity_contract']['final_runtime_owner']);
        $this->assertFalse($packet['simplicity_contract']['operator_dependency_allowed']);
        $this->assertFalse($packet['simplicity_contract']['human_or_external_provider_dependency_allowed']);
        $this->assertFalse($packet['simplicity_contract']['steady_state_requires_operator']);
    }

    public function test_task_packet_hash_ignores_generated_at_and_task_packet_id(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $a = $builder->build($this->hardValuePayload(['task_packet_id' => 'id-a']));
        $b = $builder->build($this->hardValuePayload(['task_packet_id' => 'id-b']));

        $this->assertSame($a['task_packet_hash'], $b['task_packet_hash']);
    }

    public function test_task_packet_hash_changes_when_objective_changes(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $a = $builder->build($this->hardValuePayload(['objective' => 'objective one']));
        $b = $builder->build($this->hardValuePayload(['objective' => 'objective two']));

        $this->assertNotSame($a['task_packet_hash'], $b['task_packet_hash']);
    }

    public function test_task_packet_hash_changes_when_allowed_files_change(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $a = $builder->build($this->hardValuePayload(['allowed_files' => ['app/A.php', 'tests/Unit/ATest.php']]));
        $b = $builder->build($this->hardValuePayload(['allowed_files' => ['app/B.php', 'tests/Unit/BTest.php']]));

        $this->assertNotSame($a['task_packet_hash'], $b['task_packet_hash']);
    }

    public function test_task_packet_hash_changes_when_acceptance_criteria_change(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $a = $builder->build($this->hardValuePayload(['acceptance_criteria' => ['php artisan test --filter=A']]));
        $b = $builder->build($this->hardValuePayload(['acceptance_criteria' => ['php artisan test --filter=B']]));

        $this->assertNotSame($a['task_packet_hash'], $b['task_packet_hash']);
    }
}
