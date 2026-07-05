<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphCapabilityUnlockProofCompiler;
use Tests\TestCase;

final class AtlasTaskGraphCapabilityUnlockProofCompilerTest extends TestCase
{
    private function compiler(): AtlasTaskGraphCapabilityUnlockProofCompiler
    {
        return new AtlasTaskGraphCapabilityUnlockProofCompiler;
    }

    // ── AC: tasks without downstream unlock proof fail ──

    public function test_task_without_unlock_proof_fails(): void
    {
        $result = $this->compiler()->compile([
            'task_id' => 't1',
        ]);

        $this->assertFalse($result['passed']);
        $this->assertContains('no_downstream_unlock_proof', $result['failures']);
    }

    // ── AC: repair tasks with blocker evidence pass ──

    public function test_repair_task_with_blocker_evidence_passes(): void
    {
        $result = $this->compiler()->compile([
            'task_id' => 't2',
            'removes_blocker' => 'missing_dependency',
            'blocker_evidence' => ['blocker_log_entry'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame('blocker_removal', $result['proof_type']);
    }

    public function test_repair_task_without_blocker_evidence_fails(): void
    {
        $result = $this->compiler()->compile([
            'task_id' => 't3',
            'removes_blocker' => 'missing_dependency',
            'blocker_evidence' => [],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertContains('repair_task_missing_blocker_evidence', $result['failures']);
    }

    // ── capability unlock tasks pass ──

    public function test_capability_unlock_task_passes(): void
    {
        $result = $this->compiler()->compile([
            'task_id' => 't4',
            'downstream_capability' => 'task_serving',
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame('capability_unlock', $result['proof_type']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasTaskGraphCapabilityUnlockProofCompiler::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('failures', $result);
        $this->assertArrayHasKey('proof_type', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $task = ['task_id' => 't', 'downstream_capability' => 'test'];
        $a = $this->compiler()->compile($task);
        $b = $this->compiler()->compile($task);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
