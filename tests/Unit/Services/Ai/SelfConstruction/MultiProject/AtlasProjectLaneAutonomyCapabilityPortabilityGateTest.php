<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneAutonomyCapabilityPortabilityGate;
use Tests\TestCase;

final class AtlasProjectLaneAutonomyCapabilityPortabilityGateTest extends TestCase
{
    private function gate(): AtlasProjectLaneAutonomyCapabilityPortabilityGate
    {
        return new AtlasProjectLaneAutonomyCapabilityPortabilityGate;
    }

    // ── AC: generic capabilities pass portability ──

    public function test_generic_capability_passes(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'task_serving',
            'source_lane' => 'atlas-server',
            'target_lane' => 'other-project',
        ]);

        $this->assertSame('pass', $result['verdict']);
        $this->assertSame([], $result['adaptation_tasks']);
    }

    public function test_evidence_collection_passes(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'evidence_collection',
        ]);

        $this->assertSame('pass', $result['verdict']);
    }

    // ── AC: Atlas-specific governance assumptions require adaptation tasks ──

    public function test_atlas_native_requires_adaptation(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'atlas_native_task_serving',
        ]);

        $this->assertSame('require_adaptation', $result['verdict']);
        $this->assertNotEmpty($result['adaptation_tasks']);
        $this->assertContains('replace_atlas_native_with_lane_native_owner', $result['adaptation_tasks']);
    }

    public function test_self_programming_requires_adaptation(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'self_programming',
        ]);

        $this->assertSame('require_adaptation', $result['verdict']);
    }

    public function test_atlas_brain_requires_adaptation(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'atlas_brain',
        ]);

        $this->assertSame('require_adaptation', $result['verdict']);
    }

    // ── unknown capability requires adaptation ──

    public function test_unknown_capability_requires_adaptation(): void
    {
        $result = $this->gate()->gate([
            'capability' => 'some_unknown_capability',
        ]);

        $this->assertSame('require_adaptation', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->gate([]);

        $this->assertSame(AtlasProjectLaneAutonomyCapabilityPortabilityGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('adaptation_tasks', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = ['capability' => 'task_serving'];
        $a = $this->gate()->gate($input);
        $b = $this->gate()->gate($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
