<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelAmplifierOperatingLoop;
use Tests\TestCase;

final class AtlasExternalBrainModelAmplifierOperatingLoopTest extends TestCase
{
    private function svc(): AtlasExternalBrainModelAmplifierOperatingLoop
    {
        return new AtlasExternalBrainModelAmplifierOperatingLoop;
    }

    private function decide(array $overrides = []): array
    {
        $base = [
            'proxy_leak_detected' => false,
            'benchmark_score' => 0.85,
            'frontier_available' => false,
            'escalation_budget_remaining' => false,
            'scaffold_available' => false,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => false, 'repair_signal' => false],
        ];

        return $this->svc()->decide(array_merge($base, $overrides));
    }

    public function test_decide_does_not_mutate_its_input(): void
    {
        $input = [
            'proxy_leak_detected' => true,
            'benchmark_score' => 0.85,
            'frontier_available' => false,
            'escalation_budget_remaining' => false,
            'scaffold_available' => false,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => false, 'repair_signal' => false],
        ];
        $before = $input;

        $this->svc()->decide($input);

        $this->assertSame($before, $input);
    }

    // ── priority 1: proxy leak → repair_scaffold ──────────────────────────────

    public function test_proxy_leak_triggers_repair_scaffold(): void
    {
        $r = $this->decide(['proxy_leak_detected' => true]);
        $this->assertSame('repair_scaffold', $r['decision']);
    }

    public function test_proxy_leak_overrides_retire_signal(): void
    {
        $r = $this->decide([
            'proxy_leak_detected' => true,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => true, 'repair_signal' => false],
        ]);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertSame('proxy_leak_detected', $r['rationale']);
    }

    // ── priority 2: retire signal → retire_scaffold ───────────────────────────

    public function test_retire_signal_triggers_retire_scaffold(): void
    {
        $r = $this->decide([
            'scaffold_evidence' => ['lift_score' => 0.5, 'retire_signal' => true, 'repair_signal' => false],
        ]);
        $this->assertSame('retire_scaffold', $r['decision']);
    }

    // ── priority 3: repair signal → repair_scaffold ───────────────────────────

    public function test_repair_signal_triggers_repair_scaffold(): void
    {
        $r = $this->decide([
            'scaffold_evidence' => ['lift_score' => 0.3, 'retire_signal' => false, 'repair_signal' => true],
        ]);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertSame('scaffold_repair_signal', $r['rationale']);
    }

    // ── priority 4: escalate_frontier ─────────────────────────────────────────

    public function test_frontier_escalation_when_all_conditions_met(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.60,  // below ESCALATION_SCORE_THRESHOLD(0.70)
            'frontier_available' => true,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_frontier_unavailable(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.50,
            'frontier_available' => false,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_budget_exhausted(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.50,
            'frontier_available' => true,
            'escalation_budget_remaining' => false,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    public function test_no_escalation_when_benchmark_already_high(): void
    {
        // benchmark >= ESCALATION_SCORE_THRESHOLD → no need to escalate
        $r = $this->decide([
            'benchmark_score' => 0.80,
            'frontier_available' => true,
            'escalation_budget_remaining' => true,
        ]);
        $this->assertNotSame('escalate_frontier', $r['decision']);
    }

    // ── priority 5: run_scaffolded ────────────────────────────────────────────

    public function test_run_scaffolded_when_scaffold_available_with_positive_lift(): void
    {
        $r = $this->decide([
            'scaffold_available' => true,
            'scaffold_evidence' => ['lift_score' => 0.3, 'retire_signal' => false, 'repair_signal' => false],
        ]);
        $this->assertSame('run_scaffolded', $r['decision']);
    }

    public function test_no_scaffold_run_when_lift_is_zero_or_negative(): void
    {
        $r = $this->decide([
            'scaffold_available' => true,
            'scaffold_evidence' => ['lift_score' => 0.0, 'retire_signal' => false, 'repair_signal' => false],
        ]);
        $this->assertSame('run_small', $r['decision']);
    }

    public function test_decision_output_includes_autonomy_and_escalation_fields(): void
    {
        $r = $this->decide();
        $this->assertArrayHasKey('autonomy_preservation_score', $r);
        $this->assertArrayHasKey('escalation_cost_reason', $r);
        $this->assertArrayHasKey('steady_state_safe', $r);
    }

    public function test_strong_scaffold_evidence_blocks_frontier_escalation(): void
    {
        $r = $this->decide([
            'benchmark_score' => 0.60,
            'frontier_available' => true,
            'escalation_budget_remaining' => true,
            'scaffold_available' => true,
            'scaffold_evidence' => ['lift_score' => 0.5, 'retire_signal' => false, 'repair_signal' => false],
        ]);

        $this->assertNotSame('escalate_frontier', $r['decision']);
        $this->assertSame('run_scaffolded', $r['decision']);
        $this->assertTrue($r['steady_state_safe']);
    }

    // ── default: run_small (steady-state, no frontier required) ──────────────

    public function test_default_decision_is_run_small(): void
    {
        $r = $this->decide();
        $this->assertSame('run_small', $r['decision']);
        $this->assertSame('default_steady_state', $r['rationale']);
    }

    public function test_frontier_required_always_false(): void
    {
        foreach (['run_small', 'run_scaffolded', 'escalate_frontier'] as $case) {
            $input = match ($case) {
                'run_small' => [],
                'run_scaffolded' => [
                    'scaffold_available' => true,
                    'scaffold_evidence' => ['lift_score' => 0.5, 'retire_signal' => false, 'repair_signal' => false],
                ],
                'escalate_frontier' => [
                    'benchmark_score' => 0.50,
                    'frontier_available' => true,
                    'escalation_budget_remaining' => true,
                ],
            };
            $r = $this->decide($input);
            $this->assertFalse($r['frontier_required'], "frontier_required must be false for decision {$r['decision']}");
        }
    }

    // ── receipt ───────────────────────────────────────────────────────────────

    public function test_receipt_includes_key_inputs(): void
    {
        $r = $this->decide(['benchmark_score' => 0.75, 'frontier_available' => true]);
        $this->assertArrayHasKey('proxy_leak_detected', $r['receipt']);
        $this->assertArrayHasKey('frontier_available', $r['receipt']);
        $this->assertArrayHasKey('benchmark_score', $r['receipt']);
        $this->assertSame(0.75, $r['receipt']['benchmark_score']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->decide([]);
        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::SCHEMA, $r['schema_version']);
    }

    // ── AC2: next_run_plan always present ─────────────────────────────────────

    public function test_next_run_plan_key_always_present(): void
    {
        $r = $this->svc()->decide([]);

        $this->assertArrayHasKey('next_run_plan', $r);
        $plan = $r['next_run_plan'];
        foreach (['scaffold_variant', 'context_budget', 'regression_suite', 'repair_policy', 'promotion_blocked'] as $k) {
            $this->assertArrayHasKey($k, $plan);
        }
    }

    public function test_next_run_plan_defaults(): void
    {
        $r = $this->svc()->decide([]);
        $plan = $r['next_run_plan'];

        $this->assertSame('default', $plan['scaffold_variant']);
        $this->assertSame(0, $plan['context_budget']);
        $this->assertSame([], $plan['regression_suite']);
        $this->assertSame('retry_with_stronger_scaffold', $plan['repair_policy']);
        $this->assertFalse($plan['promotion_blocked']);
    }

    // ── AC4: clean promote-ready plan ─────────────────────────────────────────

    public function test_promote_ready_plan_all_clear(): void
    {
        $r = $this->svc()->decide([
            'scaffold_variant'              => 'v2',
            'context_budget'               => 8192,
            'regression_suite'             => ['test_a', 'test_b'],
            'repair_policy'                => 'abort_on_fail',
            'held_out_regressions_failing' => false,
            'weak_output_repair_refusing'  => false,
            'give_back_risk'               => 0.10,
        ]);

        $this->assertFalse($r['next_run_plan']['promotion_blocked']);
        $this->assertSame('v2', $r['next_run_plan']['scaffold_variant']);
        $this->assertSame(8192, $r['next_run_plan']['context_budget']);
        $this->assertSame(['test_a', 'test_b'], $r['next_run_plan']['regression_suite']);
    }

    // ── AC3: regression-blocked plan ─────────────────────────────────────────

    public function test_promotion_blocked_when_held_out_regressions_failing(): void
    {
        $r = $this->svc()->decide(['held_out_regressions_failing' => true]);

        $this->assertTrue($r['next_run_plan']['promotion_blocked']);
    }

    public function test_promotion_blocked_when_weak_output_repair_refusing(): void
    {
        $r = $this->svc()->decide(['weak_output_repair_refusing' => true]);

        $this->assertTrue($r['next_run_plan']['promotion_blocked']);
    }

    public function test_promotion_blocked_when_give_back_risk_exceeds_threshold(): void
    {
        $r = $this->svc()->decide(['give_back_risk' => 0.50, 'give_back_risk_threshold' => 0.30]);

        $this->assertTrue($r['next_run_plan']['promotion_blocked']);
    }

    public function test_promotion_not_blocked_when_give_back_risk_at_threshold(): void
    {
        // strictly greater than threshold blocks; equal does not
        $r = $this->svc()->decide(['give_back_risk' => 0.30, 'give_back_risk_threshold' => 0.30]);

        $this->assertFalse($r['next_run_plan']['promotion_blocked']);
    }

    // ── AC4: repair-required plan ─────────────────────────────────────────────

    public function test_repair_required_plan_has_promotion_blocked(): void
    {
        $r = $this->svc()->decide([
            'scaffold_evidence'            => ['repair_signal' => true],
            'held_out_regressions_failing' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_REPAIR_SCAFFOLD, $r['decision']);
        $this->assertTrue($r['next_run_plan']['promotion_blocked']);
    }

    // ── AC4: degradation plan ─────────────────────────────────────────────────

    public function test_degradation_plan_escalates_frontier_with_custom_policy(): void
    {
        $r = $this->svc()->decide([
            'benchmark_score'              => 0.50,
            'frontier_available'           => true,
            'escalation_budget_remaining'  => true,
            'repair_policy'                => 'escalate_and_retry',
        ]);

        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::DECISION_ESCALATE_FRONTIER, $r['decision']);
        $this->assertSame('escalate_and_retry', $r['next_run_plan']['repair_policy']);
    }

    // ── AC4: deterministic ────────────────────────────────────────────────────

    public function test_next_run_plan_is_deterministic(): void
    {
        $input = [
            'scaffold_variant'  => 'v3',
            'context_budget'    => 4096,
            'give_back_risk'    => 0.15,
        ];

        $this->assertSame($this->svc()->decide($input), $this->svc()->decide($input));
    }

    // ── runOperatingLoop: closed loop ──────────────────────────────────────────

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'lift_evidence_present' => true,
            'lift_evidence_self_declared' => false,
            'observed_lift' => 0.2,
        ], $overrides);
    }

    public function test_operating_loop_has_five_deterministic_steps(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [$this->candidate('s1')], 'benchmark_score' => 0.5]);

        $names = array_column($r['steps'], 'step');
        $this->assertSame(['select_scaffold', 'benchmark', 'evaluate_lift', 'lifecycle_decision', 'routing_feedback'], $names);
        $this->assertSame(AtlasExternalBrainModelAmplifierOperatingLoop::SCHEMA, $r['schema_version']);
    }

    public function test_select_scaffold_picks_highest_lift_among_evidenced_candidates(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('low', ['observed_lift' => 0.1]),
            $this->candidate('high', ['observed_lift' => 0.3]),
        ]]);

        $this->assertSame('high', $r['selected_scaffold_id']);
    }

    public function test_candidate_without_evidence_is_never_selected(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('no-evidence', ['lift_evidence_present' => false, 'observed_lift' => 0.9]),
            $this->candidate('evidenced', ['observed_lift' => 0.2]),
        ]]);

        $this->assertSame('evidenced', $r['selected_scaffold_id']);
    }

    public function test_no_evidenced_candidates_blocks_promotion(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('no-evidence', ['lift_evidence_present' => false]),
        ]]);

        $this->assertNull($r['selected_scaffold_id']);
        $this->assertTrue($r['promotion_blocked']);
        $this->assertSame('keep_testing', $r['lifecycle_decision']);
    }

    public function test_self_declared_lift_evidence_blocks_promotion(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('self-declared', ['lift_evidence_self_declared' => true, 'observed_lift' => 0.9]),
        ]]);

        $this->assertTrue($r['promotion_blocked']);
        $this->assertSame('keep_testing', $r['lifecycle_decision']);
        $this->assertSame('collect_runtime_confirmed_lift_evidence_before_promoting', $r['next_action']);
    }

    public function test_verified_high_lift_promotes(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('strong', ['observed_lift' => 0.3]),
        ]]);

        $this->assertSame('promote', $r['lifecycle_decision']);
        $this->assertFalse($r['promotion_blocked']);
        $this->assertSame('promote_scaffold:strong', $r['next_action']);
    }

    public function test_verified_negative_lift_retires(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('weak', ['observed_lift' => -0.1]),
        ]]);

        $this->assertSame('retire', $r['lifecycle_decision']);
        $this->assertSame('retire_scaffold:weak', $r['next_action']);
    }

    public function test_verified_low_positive_lift_keeps_testing(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('moderate', ['observed_lift' => 0.05]),
        ]]);

        $this->assertSame('keep_testing', $r['lifecycle_decision']);
        $this->assertFalse($r['promotion_blocked']);
    }

    public function test_feedback_payload_feeds_tiered_cognition_router(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('strong', ['observed_lift' => 0.3]),
        ]]);

        $this->assertArrayHasKey('scaffold_evidence_strength', $r['feedback_payload']);
        $this->assertArrayHasKey('requires_critique_arena', $r['feedback_payload']);
        $this->assertFalse($r['feedback_payload']['requires_critique_arena']);
        $this->assertSame($r['feedback_payload'], $r['steps'][4]['feedback_payload']);
    }

    public function test_keep_testing_sets_requires_critique_arena_true(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => [
            $this->candidate('weak', ['lift_evidence_self_declared' => true]),
        ]]);

        $this->assertTrue($r['feedback_payload']['requires_critique_arena']);
    }

    public function test_no_candidates_at_all_does_not_promote(): void
    {
        $r = $this->svc()->runOperatingLoop(['candidate_scaffolds' => []]);

        $this->assertNull($r['selected_scaffold_id']);
        $this->assertSame('keep_testing', $r['lifecycle_decision']);
        $this->assertTrue($r['promotion_blocked']);
    }

    // ── worker-feed floor: low supply defers exploratory trials ──────────────

    public function test_defers_exploratory_trial_when_servable_now_below_worker_feed_floor(): void
    {
        $r = $this->svc()->runOperatingLoop([
            'servable_now' => 5,
            'active_leases' => 1,
            'candidate_scaffolds' => [
                $this->candidate('exploratory', ['observed_lift' => 0.5]),
            ],
        ]);

        $this->assertNull($r['selected_scaffold_id']);
        $this->assertSame('deferred_low_worker_feed_supply', $r['lifecycle_decision']);
        $this->assertTrue($r['promotion_blocked']);
        $this->assertSame('defer_exploratory_trial_low_worker_feed', $r['next_action']);
    }

    public function test_allows_trial_under_low_supply_when_it_improves_task_quality(): void
    {
        $r = $this->svc()->runOperatingLoop([
            'servable_now' => 5,
            'active_leases' => 1,
            'candidate_scaffolds' => [
                $this->candidate('exploratory', ['observed_lift' => 0.5]),
                $this->candidate('task-quality', ['observed_lift' => 0.3, 'improves_task_quality' => true]),
            ],
        ]);

        $this->assertSame('task-quality', $r['selected_scaffold_id']);
        $this->assertNotSame('deferred_low_worker_feed_supply', $r['lifecycle_decision']);
    }

    public function test_no_deferral_when_servable_now_at_or_above_floor(): void
    {
        $r = $this->svc()->runOperatingLoop([
            'servable_now' => 20,
            'active_leases' => 1,
            'candidate_scaffolds' => [
                $this->candidate('exploratory', ['observed_lift' => 0.5]),
            ],
        ]);

        $this->assertSame('exploratory', $r['selected_scaffold_id']);
        $this->assertNotSame('deferred_low_worker_feed_supply', $r['lifecycle_decision']);
    }

    public function test_no_deferral_when_servable_now_not_supplied(): void
    {
        $r = $this->svc()->runOperatingLoop([
            'active_leases' => 5,
            'candidate_scaffolds' => [
                $this->candidate('exploratory', ['observed_lift' => 0.5]),
            ],
        ]);

        $this->assertSame('exploratory', $r['selected_scaffold_id']);
    }

    // ── promotionCourt: held-out replay evidence and negative outcome checks ──

    public function test_missing_held_out_replay_refs_blocks_promotion(): void
    {
        $r = $this->svc()->promotionCourt([
            'lift_score' => 0.25,
            'held_out_replay_refs' => [],
        ]);
        $this->assertFalse($r['promotion_allowed']);
        $this->assertSame('keep_testing', $r['decision']);
        $this->assertContains('missing_held_out_replay_refs', $r['block_reasons']);
    }

    public function test_weak_green_outcome_blocks_promotion_and_routes_to_repair(): void
    {
        $r = $this->svc()->promotionCourt([
            'lift_score' => 0.25,
            'held_out_replay_refs' => ['replay-1'],
            'recent_outcomes' => ['weak_green'],
        ]);
        $this->assertFalse($r['promotion_allowed']);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertSame('repair_scaffold', $r['route']);
        $this->assertContains('negative_outcome:weak_green', $r['block_reasons']);
    }

    public function test_give_back_outcome_blocks_promotion(): void
    {
        $r = $this->svc()->promotionCourt([
            'lift_score' => 0.25,
            'held_out_replay_refs' => ['replay-1'],
            'recent_outcomes' => ['give_back'],
        ]);
        $this->assertFalse($r['promotion_allowed']);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertContains('negative_outcome:give_back', $r['block_reasons']);
    }

    public function test_proxy_leak_outcome_blocks_promotion(): void
    {
        $r = $this->svc()->promotionCourt([
            'lift_score' => 0.25,
            'held_out_replay_refs' => ['replay-1'],
            'recent_outcomes' => ['proxy_leak'],
        ]);
        $this->assertFalse($r['promotion_allowed']);
        $this->assertSame('repair_scaffold', $r['decision']);
        $this->assertContains('negative_outcome:proxy_leak', $r['block_reasons']);
    }

    public function test_verified_lift_with_held_out_replay_and_no_blockers_promotes(): void
    {
        $r = $this->svc()->promotionCourt([
            'lift_score' => 0.25,
            'held_out_replay_refs' => ['replay-1', 'replay-2'],
            'recent_outcomes' => [],
        ]);
        $this->assertTrue($r['promotion_allowed']);
        $this->assertSame('promote', $r['decision']);
        $this->assertSame('promote_scaffold', $r['route']);
        $this->assertFalse($r['frontier_required']);
        $this->assertSame([], $r['block_reasons']);
    }
}
