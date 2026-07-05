<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEndToEndAutonomyReplayHarness;
use Tests\TestCase;

final class AtlasExternalBrainEndToEndAutonomyReplayHarnessTest extends TestCase
{
    private function harness(): AtlasExternalBrainEndToEndAutonomyReplayHarness
    {
        return new AtlasExternalBrainEndToEndAutonomyReplayHarness();
    }

    /** @param array<string,mixed> $overrides */
    private function healthyScenario(array $overrides = []): array
    {
        return array_replace_recursive([
            'intake' => ['context_evidence_present' => true, 'owner' => 'atlas', 'evidence_ref' => 'evidence:intake:1'],
            'admission' => ['candidate_pool' => [['task_id' => 'c1'], ['task_id' => 'c2']], 'owner' => 'atlas', 'evidence_ref' => 'evidence:admission:1'],
            'enqueue_decision' => ['queue_facts' => ['poison_detected' => false, 'sprawl_pressure' => false, 'low_value_ratio' => 0.1], 'owner' => 'atlas', 'evidence_ref' => 'evidence:enqueue_decision:1'],
            'outcome_learning' => ['outcomes_recorded' => true, 'owner' => 'atlas', 'evidence_ref' => 'evidence:outcome_learning:1'],
            'next_action' => ['decision_hint' => 'continue', 'owner' => 'atlas', 'evidence_ref' => 'evidence:next_action:1'],
        ], $overrides);
    }

    // ── full cycle replay ─────────────────────────────────────────────────────

    public function test_full_cycle_replays_through_all_five_steps(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE, $result['autonomy_replay_status']);
        $this->assertNull($result['failed_step']);
        $this->assertNull($result['next_repair_hint']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::CYCLE_STEPS, $result['completed_steps']);
    }

    public function test_full_cycle_includes_intake_admission_enqueue_outcome_and_next_action(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());

        $this->assertContains('intake', $result['completed_steps']);
        $this->assertContains('admission', $result['completed_steps']);
        $this->assertContains('enqueue_decision', $result['completed_steps']);
        $this->assertContains('outcome_learning', $result['completed_steps']);
        $this->assertContains('next_action', $result['completed_steps']);
    }

    public function test_healthy_queue_produces_create_more_tasks_decision(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $result['brain_decision']);
        $this->assertSame(2, $result['candidate_count']);
    }

    public function test_poison_detected_produces_drain_poison_decision(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['poison_detected' => true]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DRAIN_POISON, $result['brain_decision']);
    }

    public function test_sprawl_pressure_produces_reduce_sprawl_decision(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['sprawl_pressure' => true]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_REDUCE_SPRAWL, $result['brain_decision']);
    }

    public function test_high_low_value_ratio_produces_deprioritize_decision(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['low_value_ratio' => 0.9]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DEPRIORITIZE_LOW_VALUE, $result['brain_decision']);
    }

    // ── evidence missing (fail closed) ────────────────────────────────────────

    public function test_missing_intake_fails_at_intake_step(): void
    {
        $scenario = $this->healthyScenario();
        unset($scenario['intake']);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('intake', $result['failed_step']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_missing_outcome_learning_fails_at_that_step_after_earlier_steps_pass(): void
    {
        $scenario = $this->healthyScenario();
        unset($scenario['outcome_learning']);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('outcome_learning', $result['failed_step']);
    }

    public function test_completely_empty_scenario_fails_at_first_step(): void
    {
        $result = $this->harness()->replay([]);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('intake', $result['failed_step']);
    }

    // ── human/operator or provider steady-state dependency (fail closed) ─────

    public function test_operator_dependency_in_admission_fails_the_cycle(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'admission' => ['requires_operator' => true],
        ]));

        $this->assertSame(
            AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
            $result['autonomy_replay_status'],
        );
        $this->assertSame('admission', $result['failed_step']);
        $this->assertStringContainsString('requires_operator', $result['next_repair_hint']);
    }

    public function test_provider_steady_state_dependency_in_next_action_fails_the_cycle(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'next_action' => ['requires_provider_steady_state' => true],
        ]));

        $this->assertSame(
            AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
            $result['autonomy_replay_status'],
        );
        $this->assertSame('next_action', $result['failed_step']);
    }

    public function test_dependency_failure_reports_completed_steps_before_the_failure(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => [], 'requires_operator' => true],
        ]));

        $this->assertSame(['intake', 'admission'], $result['completed_steps']);
    }

    // ── decision priority (preserved from prior contract) ─────────────────────

    public function test_poison_beats_sprawl(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['poison_detected' => true, 'sprawl_pressure' => true]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DRAIN_POISON, $result['brain_decision']);
    }

    public function test_sprawl_beats_low_value(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['sprawl_pressure' => true, 'low_value_ratio' => 0.90]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_REDUCE_SPRAWL, $result['brain_decision']);
    }

    public function test_low_value_at_threshold_not_deprioritized(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['low_value_ratio' => 0.60]],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_CREATE_MORE_TASKS, $result['brain_decision']);
    }

    public function test_custom_low_value_threshold_respected(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'enqueue_decision' => ['queue_facts' => ['low_value_ratio' => 0.45], 'low_value_threshold' => 0.40],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::DECISION_DEPRIORITIZE_LOW_VALUE, $result['brain_decision']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::SCHEMA, $result['schema']);
    }

    // ── AC1: native_loop_proof per-step fields ────────────────────────────────

    public function test_native_loop_proof_present_with_all_steps_and_fields(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());

        foreach (AtlasExternalBrainEndToEndAutonomyReplayHarness::CYCLE_STEPS as $step) {
            $this->assertArrayHasKey($step, $result['native_loop_proof'], "Missing proof for step: {$step}");
            foreach (['owner', 'evidence_ref', 'provider_free', 'operator_free', 'replay_freshness_status'] as $field) {
                $this->assertArrayHasKey($field, $result['native_loop_proof'][$step], "Missing {$field} for step {$step}");
            }
            $this->assertSame('atlas', $result['native_loop_proof'][$step]['owner']);
            $this->assertTrue($result['native_loop_proof'][$step]['provider_free']);
            $this->assertTrue($result['native_loop_proof'][$step]['operator_free']);
            $this->assertSame('fresh', $result['native_loop_proof'][$step]['replay_freshness_status']);
        }
    }

    public function test_native_loop_proof_reports_stale_when_step_marked_stale(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'intake' => ['stale' => true],
        ]));

        $this->assertSame('stale', $result['native_loop_proof']['intake']['replay_freshness_status']);
    }

    // ── AC2: refuse completion when owner/evidence_ref is missing ────────────

    public function test_cycle_refused_when_step_owner_is_missing_even_though_payload_exists(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'admission' => ['owner' => '', 'evidence_ref' => ''],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('admission', $result['failed_step']);
        $this->assertNotEmpty($result['next_repair_hint']);
    }

    public function test_cycle_refused_when_owner_is_not_atlas(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'outcome_learning' => ['owner' => 'operator'],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('outcome_learning', $result['failed_step']);
    }

    public function test_cycle_refused_when_evidence_ref_is_missing_even_with_valid_atlas_owner(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'next_action' => ['owner' => 'atlas', 'evidence_ref' => ''],
        ]));

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('next_action', $result['failed_step']);
        $this->assertStringContainsString('evidence_ref', (string) $result['next_repair_hint']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $scenario = $this->healthyScenario();

        $this->assertSame($this->harness()->replay($scenario), $this->harness()->replay($scenario));
    }

    // ── AC2: human/provider dependency detected ──────────────────────────────

    public function test_human_dependency_returns_human_or_provider_dependency_detected(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'intake' => ['requires_operator' => true],
        ]));

        $this->assertSame(
            AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
            $result['autonomy_replay_status'],
        );
    }

    public function test_provider_dependency_returns_human_or_provider_dependency_detected(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'admission' => ['requires_provider_steady_state' => true],
        ]));

        $this->assertSame(
            AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
            $result['autonomy_replay_status'],
        );
    }

    // ── AC3: cycle_complete only when all steps have evidence ─────────────────

    public function test_cycle_complete_requires_all_five_steps_with_evidence(): void
    {
        $result = $this->harness()->replay($this->healthyScenario());

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE, $result['autonomy_replay_status']);
        $this->assertCount(5, $result['completed_steps']);
        $this->assertCount(5, $result['native_loop_proof']);
    }

    // ── AC4: missing evidence returns repair-oriented next_decision ───────────

    public function test_missing_evidence_returns_repair_oriented_next_repair_hint(): void
    {
        $scenario = $this->healthyScenario();
        unset($scenario['intake']);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertNotNull($result['next_repair_hint']);
        $this->assertStringContainsString('intake', $result['next_repair_hint']);
    }

    public function test_missing_evidence_does_not_return_create_more_tasks(): void
    {
        $scenario = $this->healthyScenario();
        unset($scenario['outcome_learning']);

        $result = $this->harness()->replay($scenario);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertArrayNotHasKey('brain_decision', $result);
    }

    public function test_dependency_detected_returns_repair_hint_not_create_more_tasks(): void
    {
        $result = $this->harness()->replay($this->healthyScenario([
            'next_action' => ['requires_operator' => true],
        ]));

        $this->assertSame(
            AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED,
            $result['autonomy_replay_status'],
        );
        $this->assertNotNull($result['next_repair_hint']);
        $this->assertStringContainsString('Remove', $result['next_repair_hint']);
        $this->assertArrayNotHasKey('brain_decision', $result);
    }
}
