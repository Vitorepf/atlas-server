<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\StrategyCouncil;

use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilAntiGoodhartVeto;
use Tests\TestCase;

final class AtlasStrategyCouncilAntiGoodhartVetoTest extends TestCase
{
    private function veto(): AtlasStrategyCouncilAntiGoodhartVeto
    {
        return new AtlasStrategyCouncilAntiGoodhartVeto;
    }

    // ── AC: proxy wins are vetoed ──

    public function test_queue_depth_proxy_vetoed(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Increase queue depth by 10',
        ]);

        $this->assertTrue($result['vetoed']);
    }

    public function test_test_count_proxy_vetoed(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Add more tests to increase test count',
        ]);

        $this->assertTrue($result['vetoed']);
    }

    public function test_wrapper_count_proxy_vetoed(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Add wrapper count to codebase',
        ]);

        $this->assertTrue($result['vetoed']);
    }

    // ── AC: capability-changing repair or learning batches pass ──

    public function test_repair_batch_passes(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Fix the repair pipeline for task serving',
            'capability_change' => 'repair',
        ]);

        $this->assertFalse($result['vetoed']);
    }

    public function test_learning_batch_passes(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Implement learning feedback loop',
            'capability_change' => 'learning',
        ]);

        $this->assertFalse($result['vetoed']);
    }

    public function test_capability_expansion_passes(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Expand capability for autonomy',
            'capability_change' => 'capability_expansion',
        ]);

        $this->assertFalse($result['vetoed']);
    }

    // ── proxy with capability change passes ──

    public function test_proxy_with_capability_change_passes(): void
    {
        $result = $this->veto()->veto([
            'objective' => 'Increase queue depth while adding repair capability',
            'capability_change' => 'repair',
        ]);

        $this->assertFalse($result['vetoed']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->veto()->veto([]);

        $this->assertSame(AtlasStrategyCouncilAntiGoodhartVeto::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('vetoed', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $batch = ['objective' => 'test count increase'];
        $a = $this->veto()->veto($batch);
        $b = $this->veto()->veto($batch);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
