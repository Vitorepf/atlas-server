<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorImpactDiversityReport;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorImpactDiversityReportTest extends TestCase
{
    private function report(): AtlasExternalBrainOriginatorImpactDiversityReport
    {
        return new AtlasExternalBrainOriginatorImpactDiversityReport;
    }

    private function task(string $impactClass, array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'task-'.$impactClass.'-'.bin2hex(random_bytes(4)),
            'impact_class' => $impactClass,
            'objective' => 'harden the '.$impactClass.' path so it is deterministic',
        ], $overrides);
    }

    // ── AC2: per-task proof_demand / structural_leverage / repeated_template_risk ──

    public function test_classified_task_carries_proof_demand_structural_leverage_and_template_risk(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task('task_fabric', ['proof_demand_score' => 0.9, 'structural_leverage_score' => 0.5]),
        ]]);

        $row = $result['classified_tasks'][0];
        $this->assertArrayHasKey('proof_demand', $row);
        $this->assertArrayHasKey('structural_leverage', $row);
        $this->assertArrayHasKey('repeated_template_risk', $row);
        $this->assertSame('high', $row['proof_demand']);
        $this->assertSame('medium', $row['structural_leverage']);
    }

    public function test_summaries_present_in_output(): void
    {
        $result = $this->report()->report(['tasks' => [$this->task('task_fabric')]]);

        foreach (['proof_demand_summary', 'structural_leverage_summary', 'repeated_template_risk_summary'] as $key) {
            $this->assertArrayHasKey($key, $result);
            foreach (['low', 'medium', 'high'] as $bucket) {
                $this->assertArrayHasKey($bucket, $result[$key]);
            }
        }
    }

    public function test_low_leverage_and_proof_demand_default_to_low_bucket(): void
    {
        $result = $this->report()->report(['tasks' => [$this->task('task_fabric')]]);

        $row = $result['classified_tasks'][0];
        $this->assertSame('low', $row['proof_demand']);
        $this->assertSame('low', $row['structural_leverage']);
    }

    public function test_repeated_objective_and_class_is_flagged_high_template_risk(): void
    {
        $identicalObjective = 'harden the task fabric wave planner path deterministically';
        $tasks = array_map(
            fn (int $i) => $this->task('task_fabric', ['task_packet_id' => "t{$i}", 'objective' => $identicalObjective]),
            range(1, 3),
        );

        $result = $this->report()->report(['tasks' => $tasks]);

        foreach ($result['classified_tasks'] as $row) {
            $this->assertSame('high', $row['repeated_template_risk']);
        }
        $this->assertSame(3, $result['repeated_template_risk_summary']['high']);
    }

    public function test_unique_objective_is_low_template_risk(): void
    {
        $result = $this->report()->report(['tasks' => [
            $this->task('task_fabric', ['objective' => 'a totally unique first-of-its-kind objective']),
        ]]);

        $this->assertSame('low', $result['classified_tasks'][0]['repeated_template_risk']);
    }

    // ── AC3: over-served families flagged with recommended next focus ─────────

    public function test_over_served_family_is_flagged(): void
    {
        $tasks = array_merge(
            array_map(fn (int $i) => $this->task('task_fabric', ['task_packet_id' => "f{$i}", 'task_family' => 'repeated_give_back']), range(1, 4)),
            [$this->task('outcome_learning', ['task_family' => 'other_family'])],
        );

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertNotEmpty($result['over_served_families']);
        $this->assertSame('repeated_give_back', $result['over_served_families'][0]['family']);

        $actions = array_column($result['recommended_actions'], 'target_family');
        $this->assertContains('repeated_give_back', $actions);
    }

    public function test_evenly_distributed_families_are_not_flagged(): void
    {
        $tasks = [
            $this->task('task_fabric', ['task_family' => 'family_a']),
            $this->task('outcome_learning', ['task_family' => 'family_b']),
        ];

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertSame([], $result['over_served_families']);
    }

    // ── AC4: volume-only batches are discounted, never rewarded for raw count ──

    public function test_large_low_diversity_low_leverage_batch_is_flagged_volume_only(): void
    {
        $tasks = array_map(
            fn (int $i) => $this->task('task_fabric', ['task_packet_id' => "v{$i}", 'objective' => "distinct filler objective number {$i}"]),
            range(1, 6),
        );

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertTrue($result['is_volume_only_batch']);
        $this->assertSame('replace', $result['recommendation']['action']);
        $this->assertStringContainsString('volume-only', $result['recommendation']['reasons'][0]);
    }

    public function test_large_batch_with_high_leverage_task_is_not_volume_only(): void
    {
        $tasks = array_map(
            fn (int $i) => $this->task('task_fabric', ['task_packet_id' => "v{$i}", 'objective' => "distinct filler objective number {$i}"]),
            range(1, 5),
        );
        $tasks[] = $this->task('task_fabric', ['task_packet_id' => 'leverage', 'structural_leverage_score' => 0.9]);

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertFalse($result['is_volume_only_batch']);
    }

    public function test_small_low_diversity_batch_is_not_volume_only(): void
    {
        // Below the minimum batch size threshold — too small to call "volume-only".
        $tasks = [$this->task('task_fabric'), $this->task('task_fabric', ['objective' => 'a different one'])];

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertFalse($result['is_volume_only_batch']);
    }

    public function test_diverse_batch_is_never_volume_only(): void
    {
        $tasks = [
            $this->task('task_fabric'),
            $this->task('outcome_learning'),
            $this->task('queue_self_healing'),
            $this->task('model_amplifier'),
            $this->task('autonomy_governor'),
            $this->task('simplification'),
        ];

        $result = $this->report()->report(['tasks' => $tasks]);

        $this->assertFalse($result['is_volume_only_batch']);
    }
}
