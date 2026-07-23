<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsolidationRollbackPlanner;
use Tests\TestCase;

final class AtlasExternalBrainConsolidationRollbackPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainConsolidationRollbackPlanner
    {
        return new AtlasExternalBrainConsolidationRollbackPlanner;
    }

    private function completeAction(string $id, string $kind): array
    {
        return [
            'action_id' => $id,
            'kind' => $kind,
            'restoration_files' => ["app/Foo/{$id}.php"],
            'restoration_contracts' => ["preserve public API of {$id}"],
            'verification_commands' => ["php artisan test --filter={$id}Test"],
        ];
    }

    public function test_schema_present(): void
    {
        $r = $this->planner()->plan(['actions' => []]);
        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::SCHEMA, $r['schema']);
    }

    public function test_empty_wave_is_approved(): void
    {
        $r = $this->planner()->plan(['actions' => []]);
        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_APPROVED, $r['verdict']);
    }

    // ── AC: approve only when delete/merge/inline actions have all 3 rollback parts ──

    public function test_complete_delete_action_is_approved(): void
    {
        $r = $this->planner()->plan(['actions' => [$this->completeAction('d1', 'delete')]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_APPROVED, $r['verdict']);
        $this->assertTrue($r['action_results'][0]['rollback_complete']);
        $this->assertSame([], $r['action_results'][0]['missing_rollback_parts']);
    }

    public function test_complete_merge_action_is_approved(): void
    {
        $r = $this->planner()->plan(['actions' => [$this->completeAction('m1', 'merge')]]);
        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_APPROVED, $r['verdict']);
    }

    public function test_complete_inline_action_is_approved(): void
    {
        $r = $this->planner()->plan(['actions' => [$this->completeAction('i1', 'inline')]]);
        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_APPROVED, $r['verdict']);
    }

    public function test_non_destructive_action_never_requires_rollback_evidence(): void
    {
        $r = $this->planner()->plan(['actions' => [[
            'action_id' => 's1',
            'kind' => 'simplify',
        ]]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_APPROVED, $r['verdict']);
        $this->assertFalse($r['action_results'][0]['requires_rollback']);
        $this->assertTrue($r['action_results'][0]['rollback_complete']);
    }

    // ── AC: missing rollback evidence produces hold with exact missing parts ──

    public function test_missing_restoration_files_produces_hold_with_exact_part(): void
    {
        $action = $this->completeAction('d2', 'delete');
        unset($action['restoration_files']);

        $r = $this->planner()->plan(['actions' => [$action]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('missing_restoration_files', $r['action_results'][0]['missing_rollback_parts']);
        $this->assertContains('d2:missing_restoration_files', $r['missing_rollback_summary']);
    }

    public function test_missing_restoration_contracts_produces_hold_with_exact_part(): void
    {
        $action = $this->completeAction('m2', 'merge');
        unset($action['restoration_contracts']);

        $r = $this->planner()->plan(['actions' => [$action]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('missing_restoration_contracts', $r['action_results'][0]['missing_rollback_parts']);
    }

    public function test_missing_verification_commands_produces_hold_with_exact_part(): void
    {
        $action = $this->completeAction('i2', 'inline');
        unset($action['verification_commands']);

        $r = $this->planner()->plan(['actions' => [$action]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('missing_verification_commands', $r['action_results'][0]['missing_rollback_parts']);
    }

    public function test_all_three_parts_missing_lists_all_three(): void
    {
        $r = $this->planner()->plan(['actions' => [[
            'action_id' => 'd3',
            'kind' => 'delete',
        ]]]);

        $missing = $r['action_results'][0]['missing_rollback_parts'];
        $this->assertContains('missing_restoration_files', $missing);
        $this->assertContains('missing_restoration_contracts', $missing);
        $this->assertContains('missing_verification_commands', $missing);
        $this->assertCount(3, $missing);
    }

    public function test_one_incomplete_action_holds_the_whole_wave_even_with_other_complete_actions(): void
    {
        $r = $this->planner()->plan(['actions' => [
            $this->completeAction('good', 'delete'),
            ['action_id' => 'bad', 'kind' => 'merge'],
        ]]);

        $this->assertSame(AtlasExternalBrainConsolidationRollbackPlanner::VERDICT_HOLD, $r['verdict']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $wave = ['actions' => [$this->completeAction('a', 'delete'), ['action_id' => 'b', 'kind' => 'merge']]];

        $this->assertSame(
            json_encode($this->planner()->plan($wave)),
            json_encode($this->planner()->plan($wave)),
        );
    }

    public function test_malformed_actions_are_skipped(): void
    {
        $r = $this->planner()->plan(['actions' => [
            ['kind' => 'delete'], // missing action_id
            $this->completeAction('valid', 'delete'),
        ]]);

        $this->assertCount(1, $r['action_results']);
        $this->assertSame('valid', $r['action_results'][0]['action_id']);
    }
}
