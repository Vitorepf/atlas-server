<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGiveBackToQueueRepairPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGiveBackToQueueRepairPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainGiveBackToQueueRepairPlanner
    {
        return new AtlasExternalBrainGiveBackToQueueRepairPlanner;
    }

    // ── planBatches(): AC2 output shape ────────────────────────────────────────

    public function test_batch_has_required_fields(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Foo.php'], 'unblock_count' => 2, 'token_savings' => 500.0],
            ],
        ]);

        $batch = $r['batches'][0];
        foreach (['target_file', 'action', 'safety_score', 'give_back_count', 'unblock_count', 'token_savings', 'required_scope_changes', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $batch, "Missing key: {$k}");
        }
        $this->assertSame(AtlasExternalBrainGiveBackToQueueRepairPlanner::SCHEMA, $r['schema']);
    }

    // ── AC4: safe repair batch ──────────────────────────────────────────────────

    public function test_safe_repair_batch_is_action_repair_with_full_safety_score(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Foo.php']],
            ],
        ]);

        $batch = $r['batches'][0];
        $this->assertSame(AtlasExternalBrainGiveBackToQueueRepairPlanner::ACTION_REPAIR, $batch['action']);
        $this->assertSame(1.0, $batch['safety_score']);
        $this->assertSame(['app/Foo.php'], $batch['required_scope_changes']);
    }

    // ── AC3/AC4: unsafe forbidden target ─────────────────────────────────────────

    public function test_forbidden_target_batch_is_refused_with_zero_safety_score(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Forbidden.php'], 'forbidden_target' => true],
            ],
        ]);

        $batch = $r['batches'][0];
        $this->assertSame(AtlasExternalBrainGiveBackToQueueRepairPlanner::ACTION_REFUSE, $batch['action']);
        $this->assertSame(0.0, $batch['safety_score']);
        $this->assertStringContainsString('forbidden', $batch['reason']);
    }

    public function test_operator_only_without_classification_is_refused(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Sensitive.php'], 'requires_operator_only_files' => true],
            ],
        ]);

        $batch = $r['batches'][0];
        $this->assertSame(AtlasExternalBrainGiveBackToQueueRepairPlanner::ACTION_REFUSE, $batch['action']);
        $this->assertSame(0.2, $batch['safety_score']);
    }

    public function test_operator_only_with_explicit_classification_is_repaired(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                [
                    'task_id' => 't1',
                    'missing_files' => ['app/Sensitive.php'],
                    'requires_operator_only_files' => true,
                    'operator_only_classification_confirmed' => true,
                ],
            ],
        ]);

        $batch = $r['batches'][0];
        $this->assertSame(AtlasExternalBrainGiveBackToQueueRepairPlanner::ACTION_REPAIR, $batch['action']);
        $this->assertSame(0.8, $batch['safety_score']);
    }

    // ── AC4: duplicate give_back collapse ────────────────────────────────────────

    public function test_repeated_give_backs_against_same_target_collapse_into_one_batch(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Foo.php'], 'unblock_count' => 1, 'token_savings' => 100.0],
                ['task_id' => 't2', 'missing_files' => ['app/Foo.php'], 'unblock_count' => 2, 'token_savings' => 200.0],
                ['task_id' => 't3', 'missing_files' => ['app/Foo.php'], 'unblock_count' => 1, 'token_savings' => 150.0],
            ],
        ]);

        $this->assertCount(1, $r['batches']);
        $batch = $r['batches'][0];
        $this->assertSame(3, $batch['give_back_count']);
        $this->assertSame(4, $batch['unblock_count']);
        $this->assertSame(450.0, $batch['token_savings']);
    }

    // ── AC4: token-savings ordering ───────────────────────────────────────────────

    public function test_batches_ordered_by_token_savings_descending(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Low.php'], 'token_savings' => 100.0],
                ['task_id' => 't2', 'missing_files' => ['app/High.php'], 'token_savings' => 900.0],
            ],
        ]);

        $this->assertSame('app/High.php', $r['batches'][0]['target_file']);
        $this->assertSame('app/Low.php', $r['batches'][1]['target_file']);
    }

    public function test_deterministic_tie_break_by_target_file(): void
    {
        $r = $this->planner()->planBatches([
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Zeta.php'], 'token_savings' => 100.0],
                ['task_id' => 't2', 'missing_files' => ['app/Alpha.php'], 'token_savings' => 100.0],
            ],
        ]);

        $this->assertSame('app/Alpha.php', $r['batches'][0]['target_file']);
        $this->assertSame('app/Zeta.php', $r['batches'][1]['target_file']);
    }

    public function test_plan_batches_is_deterministic(): void
    {
        $input = [
            'give_backs' => [
                ['task_id' => 't1', 'missing_files' => ['app/Foo.php'], 'token_savings' => 100.0],
                ['task_id' => 't2', 'missing_files' => ['app/Bar.php'], 'forbidden_target' => true],
            ],
        ];

        $a = $this->planner()->planBatches($input);
        $b = $this->planner()->planBatches($input);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_give_backs_returns_empty_batches(): void
    {
        $r = $this->planner()->planBatches([]);
        $this->assertSame([], $r['batches']);
    }

    // ── plan() basics still work (legacy per-event candidate API) ────────────────

    public function test_plan_returns_repair_candidates_for_missing_files(): void
    {
        $r = $this->planner()->plan(['give_backs' => [
            ['task_id' => 't1', 'missing_files' => ['app/Foo.php']],
        ]]);

        $this->assertSame(1, $r['candidate_count']);
        $this->assertSame('add_allowed_file', $r['repair_candidates'][0]['repair_plan']);
    }
}
