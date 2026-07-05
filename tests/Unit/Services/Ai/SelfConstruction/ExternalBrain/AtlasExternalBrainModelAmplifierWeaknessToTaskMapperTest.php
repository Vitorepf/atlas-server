<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelAmplifierWeaknessToTaskMapper;
use Tests\TestCase;

final class AtlasExternalBrainModelAmplifierWeaknessToTaskMapperTest extends TestCase
{
    private function mapper(): AtlasExternalBrainModelAmplifierWeaknessToTaskMapper
    {
        return new AtlasExternalBrainModelAmplifierWeaknessToTaskMapper;
    }

    // ── AC: weak-output, context-loss and overconfident-green signals each emit a different task family ──

    public function test_weak_output_emits_evidence_strengthening(): void
    {
        $result = $this->mapper()->map([
            ['type' => 'weak_output', 'evidence' => 'low_quality', 'source_task_id' => 't1'],
        ]);

        $this->assertSame('evidence_strengthening', $result['tasks'][0]['task_family']);
    }

    public function test_context_loss_emits_context_preservation(): void
    {
        $result = $this->mapper()->map([
            ['type' => 'context_loss', 'evidence' => 'lost_context', 'source_task_id' => 't2'],
        ]);

        $this->assertSame('context_preservation', $result['tasks'][0]['task_family']);
    }

    public function test_overconfident_green_emits_green_verification(): void
    {
        $result = $this->mapper()->map([
            ['type' => 'overconfident_green', 'evidence' => 'unverified_green', 'source_task_id' => 't3'],
        ]);

        $this->assertSame('green_verification', $result['tasks'][0]['task_family']);
    }

    public function test_each_weakness_emits_different_task_family(): void
    {
        $result = $this->mapper()->map([
            ['type' => 'weak_output', 'evidence' => 'a'],
            ['type' => 'context_loss', 'evidence' => 'b'],
            ['type' => 'overconfident_green', 'evidence' => 'c'],
        ]);

        $families = array_column($result['tasks'], 'task_family');
        $this->assertCount(3, array_unique($families));
    }

    // ── unknown weakness ignored ──

    public function test_unknown_weakness_ignored(): void
    {
        $result = $this->mapper()->map([
            ['type' => 'unknown_weakness', 'evidence' => 'x'],
        ]);

        $this->assertSame([], $result['tasks']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->mapper()->map([]);

        $this->assertSame(AtlasExternalBrainModelAmplifierWeaknessToTaskMapper::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertArrayHasKey('total_tasks', $result);
        $this->assertArrayHasKey('task_families', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $weaknesses = [
            ['type' => 'context_loss', 'evidence' => 'b'],
            ['type' => 'weak_output', 'evidence' => 'a'],
        ];

        $a = $this->mapper()->map($weaknesses);
        $b = $this->mapper()->map($weaknesses);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
