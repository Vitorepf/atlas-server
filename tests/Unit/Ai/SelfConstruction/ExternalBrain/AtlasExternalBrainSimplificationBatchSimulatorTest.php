<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationBatchSimulator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationBatchSimulatorTest extends TestCase
{
    private function svc(): AtlasExternalBrainSimplificationBatchSimulator
    {
        return new AtlasExternalBrainSimplificationBatchSimulator;
    }

    private function task(string $id, array $files, string $risk = 'low', bool $proofReady = true, int $capacity = 1): array
    {
        return [
            'id' => $id,
            'allowed_files' => $files,
            'risk_level' => $risk,
            'proof_ready' => $proofReady,
            'worker_capacity_cost' => $capacity,
        ];
    }

    // ── approved batch case ────────────────────────────────────────────────────

    public function test_clean_batch_within_all_limits_is_approved(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/A.php'], 'low'),
            $this->task('b', ['app/B.php'], 'low'),
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_APPROVED, $result['decision']);
        $this->assertSame([], $result['overlapping_files']);
        $this->assertSame([], $result['split_recommendation']);
    }

    // ── overlap split case ──────────────────────────────────────────────────────

    public function test_overlapping_write_sets_produce_split_recommendation(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/Shared.php'], 'low'),
            $this->task('b', ['app/Shared.php'], 'low'),
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_SPLIT, $result['decision']);
        $this->assertContains('overlapping_write_sets', $result['reasons']);
        $this->assertSame('app/Shared.php', $result['overlapping_files'][0]['file']);
        $this->assertSame(['a', 'b'], $result['overlapping_files'][0]['task_ids']);

        // The two overlapping tasks must never land in the same sub-batch.
        $subBatchOf = [];
        foreach ($result['split_recommendation'] as $i => $sub) {
            foreach ($sub['task_ids'] as $taskId) {
                $subBatchOf[$taskId] = $i;
            }
        }
        $this->assertNotSame($subBatchOf['a'], $subBatchOf['b']);
    }

    public function test_no_overlap_between_files_is_not_flagged(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/A.php'], 'low'),
            $this->task('b', ['app/B.php'], 'low'),
        ]]);

        $this->assertNotContains('overlapping_write_sets', $result['reasons']);
    }

    // ── combined risk split ────────────────────────────────────────────────────

    public function test_excessive_combined_risk_produces_split(): void
    {
        $result = $this->svc()->simulate([
            'tasks' => [
                $this->task('a', ['app/A.php'], 'high'),
                $this->task('b', ['app/B.php'], 'high'),
            ],
            'max_combined_risk' => 6,
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_SPLIT, $result['decision']);
        $this->assertContains('combined_risk_exceeds_limit', $result['reasons']);
        $this->assertSame(8, $result['combined_risk_score']);
    }

    public function test_combined_risk_within_limit_is_not_flagged(): void
    {
        $result = $this->svc()->simulate([
            'tasks' => [
                $this->task('a', ['app/A.php'], 'low'),
                $this->task('b', ['app/B.php'], 'medium'),
            ],
            'max_combined_risk' => 6,
        ]);

        $this->assertNotContains('combined_risk_exceeds_limit', $result['reasons']);
    }

    // ── worker capacity split ──────────────────────────────────────────────────

    public function test_worker_capacity_exceeded_produces_split(): void
    {
        $result = $this->svc()->simulate([
            'tasks' => [
                $this->task('a', ['app/A.php'], 'low', true, 3),
                $this->task('b', ['app/B.php'], 'low', true, 3),
            ],
            'available_worker_capacity' => 4,
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_SPLIT, $result['decision']);
        $this->assertContains('worker_capacity_exceeded', $result['reasons']);
        $this->assertSame(6, $result['worker_capacity_used']);
        $this->assertSame(4, $result['available_worker_capacity']);
    }

    public function test_no_capacity_limit_supplied_never_triggers_capacity_split(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/A.php'], 'low', true, 1000),
        ]]);

        $this->assertNotContains('worker_capacity_exceeded', $result['reasons']);
        $this->assertNull($result['available_worker_capacity']);
    }

    // ── proof readiness ─────────────────────────────────────────────────────────

    public function test_all_tasks_proof_unready_holds_the_whole_batch(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/A.php'], 'low', false),
            $this->task('b', ['app/B.php'], 'low', false),
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_HOLD, $result['decision']);
        $this->assertContains('no_proof_ready_tasks', $result['reasons']);
        $this->assertSame(['a', 'b'], $result['prework_required_task_ids']);
    }

    public function test_partially_unready_batch_splits_and_pulls_out_the_unready_task(): void
    {
        $result = $this->svc()->simulate(['tasks' => [
            $this->task('a', ['app/A.php'], 'low', true),
            $this->task('b', ['app/B.php'], 'low', false),
        ]]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_SPLIT, $result['decision']);
        $this->assertContains('unready_tasks_present', $result['reasons']);
        $this->assertSame(['b'], $result['prework_required_task_ids']);

        foreach ($result['split_recommendation'] as $sub) {
            $this->assertNotContains('b', $sub['task_ids']);
        }
    }

    // ── worker capacity holds the whole batch ──────────────────────────────────

    public function test_zero_available_capacity_holds_the_whole_batch(): void
    {
        $result = $this->svc()->simulate([
            'tasks' => [$this->task('a', ['app/A.php'])],
            'available_worker_capacity' => 0,
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_HOLD, $result['decision']);
        $this->assertContains('no_worker_capacity', $result['reasons']);
    }

    public function test_empty_batch_is_held(): void
    {
        $result = $this->svc()->simulate(['tasks' => []]);

        $this->assertSame(AtlasExternalBrainSimplificationBatchSimulator::DECISION_HOLD, $result['decision']);
        $this->assertContains('empty_batch', $result['reasons']);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.simplification_batch_simulator.v1', AtlasExternalBrainSimplificationBatchSimulator::SCHEMA);
    }

    public function test_result_is_deterministic(): void
    {
        $svc = $this->svc();
        $facts = ['tasks' => [
            $this->task('a', ['app/Shared.php'], 'high'),
            $this->task('b', ['app/Shared.php'], 'high'),
            $this->task('c', ['app/C.php'], 'low'),
        ]];

        $this->assertSame(json_encode($svc->simulate($facts)), json_encode($svc->simulate($facts)));
    }
}
