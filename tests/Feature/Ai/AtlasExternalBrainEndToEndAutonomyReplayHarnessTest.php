<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEndToEndAutonomyReplayHarness;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEndToEndAutonomyReplayHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainEndToEndAutonomyReplayHarness
    {
        return new AtlasExternalBrainEndToEndAutonomyReplayHarness;
    }

    private function completeScenario(array $overrides = []): array
    {
        return array_merge([
            'intake' => [],
            'admission' => ['candidate_pool' => [['id' => 'c1']]],
            'enqueue_decision' => ['queue_facts' => ['low_value_ratio' => 0.1]],
            'outcome_learning' => [],
            'next_action' => [],
        ], $overrides);
    }

    // ── AC2: every required step must be present or replay fails evidence_missing ──

    public function test_missing_any_required_step_returns_evidence_missing_with_failed_step(): void
    {
        foreach (AtlasExternalBrainEndToEndAutonomyReplayHarness::CYCLE_STEPS as $step) {
            $scenario = $this->completeScenario();
            unset($scenario[$step]);

            $result = $this->harness()->replay($scenario);

            $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status'], "step: {$step}");
            $this->assertSame($step, $result['failed_step']);
            $this->assertNotEmpty($result['next_repair_hint']);
            $this->assertSame([], $result['completed_steps']);
        }
    }

    // ── AC3: operator/provider steady-state dependency fails at that step ────

    public function test_operator_dependency_at_any_step_returns_human_or_provider_dependency_detected(): void
    {
        $scenario = $this->completeScenario(['outcome_learning' => ['requires_operator' => true]]);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED, $result['autonomy_replay_status']);
        $this->assertSame('outcome_learning', $result['failed_step']);
        $this->assertNotContains('outcome_learning', $result['completed_steps']);
        $this->assertSame(['intake', 'admission', 'enqueue_decision'], $result['completed_steps']);
    }

    public function test_provider_steady_state_dependency_at_any_step_returns_human_or_provider_dependency_detected(): void
    {
        $scenario = $this->completeScenario(['admission' => ['candidate_pool' => [], 'requires_provider_steady_state' => true]]);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED, $result['autonomy_replay_status']);
        $this->assertSame('admission', $result['failed_step']);
        $this->assertNotContains('admission', $result['completed_steps']);
    }

    // ── AC4: complete cycle chooses decision per queue_facts priority ────────

    public function test_complete_cycle_chooses_drain_poison_with_highest_priority(): void
    {
        $result = $this->harness()->replay($this->completeScenario([
            'enqueue_decision' => ['queue_facts' => ['poison_detected' => true, 'sprawl_pressure' => true, 'low_value_ratio' => 0.9]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE, $result['autonomy_replay_status']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DRAIN_POISON, $result['brain_decision']);
    }

    public function test_complete_cycle_chooses_reduce_sprawl_when_no_poison(): void
    {
        $result = $this->harness()->replay($this->completeScenario([
            'enqueue_decision' => ['queue_facts' => ['sprawl_pressure' => true, 'low_value_ratio' => 0.9]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_REDUCE_SPRAWL, $result['brain_decision']);
    }

    public function test_complete_cycle_chooses_deprioritize_low_value_when_ratio_exceeds_threshold(): void
    {
        $result = $this->harness()->replay($this->completeScenario([
            'enqueue_decision' => ['queue_facts' => ['low_value_ratio' => 0.75]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DEPRIORITIZE_LOW_VALUE, $result['brain_decision']);
    }

    public function test_complete_cycle_chooses_create_more_tasks_when_healthy(): void
    {
        $result = $this->harness()->replay($this->completeScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $result['brain_decision']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::CYCLE_STEPS, $result['completed_steps']);
        $this->assertNull($result['failed_step']);
        $this->assertNull($result['next_repair_hint']);
    }
}
