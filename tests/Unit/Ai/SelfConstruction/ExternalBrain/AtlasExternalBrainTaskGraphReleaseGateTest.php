<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphReleaseGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskGraphReleaseGateTest extends TestCase
{
    private AtlasExternalBrainTaskGraphReleaseGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainTaskGraphReleaseGate();
    }

    // AC 2: dependent task blocked when prerequisite is missing, quarantined or not implemented
    public function test_blocked_when_prerequisite_missing(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [],
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('missing_evidence', $result['reason']);
    }

    public function test_blocked_when_prerequisite_quarantined(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => true, 'quarantined' => true],
            ],
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('quarantined', $result['reason']);
    }

    public function test_blocked_when_prerequisite_not_implemented(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => false, 'proof_passed' => false, 'quarantined' => false],
            ],
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('not_implemented', $result['reason']);
    }

    public function test_blocked_when_prerequisite_implemented_but_no_proof(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => false, 'quarantined' => false],
            ],
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('proof_not_passed', $result['reason']);
    }

    // AC 3: release allowed when prerequisite evidence and runnable proof are present
    public function test_released_when_prerequisites_met_with_proof(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => true, 'quarantined' => false],
            ],
            'namespace_lane_safe' => true,
            'worker_capacity_available' => true,
        ]);

        $this->assertSame('release', $result['decision']);
    }

    public function test_blocked_when_namespace_lane_unsafe(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => true, 'quarantined' => false],
            ],
            'namespace_lane_safe' => false,
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('namespace', $result['reason']);
    }

    public function test_blocked_when_worker_capacity_unavailable(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => true, 'quarantined' => false],
            ],
            'namespace_lane_safe' => true,
            'worker_capacity_available' => false,
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('capacity', $result['reason']);
    }

    public function test_root_node_with_no_prerequisites_released(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-root',
            'prerequisite_task_ids' => [],
        ]);

        $this->assertSame('release', $result['decision']);
    }

    // AC 4: release decisions include human-readable reason, required prereq ids, next safe chain step
    public function test_output_includes_reason_prereq_ids_and_next_step(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-B',
            'prerequisite_task_ids' => ['task-A'],
            'prerequisite_evidence' => [],
        ]);

        $this->assertArrayHasKey('reason', $result);
        $this->assertIsString($result['reason']);
        $this->assertNotEmpty($result['reason']);

        $this->assertArrayHasKey('required_prerequisite_ids', $result);
        $this->assertContains('task-A', $result['required_prerequisite_ids']);

        $this->assertArrayHasKey('next_safe_chain_step', $result);
        $this->assertIsString($result['next_safe_chain_step']);
        $this->assertNotEmpty($result['next_safe_chain_step']);
    }

    public function test_multiple_prerequisites_all_must_pass(): void
    {
        $result = $this->gate->evaluate([
            'task_id' => 'task-C',
            'prerequisite_task_ids' => ['task-A', 'task-B'],
            'prerequisite_evidence' => [
                'task-A' => ['implemented' => true, 'proof_passed' => true, 'quarantined' => false],
                'task-B' => ['implemented' => true, 'proof_passed' => false, 'quarantined' => false],
            ],
        ]);

        $this->assertSame('block', $result['decision']);
        $this->assertStringContainsString('task-B', $result['reason']);
    }
}
