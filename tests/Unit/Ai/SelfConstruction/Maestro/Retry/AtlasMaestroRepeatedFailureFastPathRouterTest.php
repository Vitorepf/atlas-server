<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroRepeatedFailureFastPathRouter;
use Tests\TestCase;

final class AtlasMaestroRepeatedFailureFastPathRouterTest extends TestCase
{
    private function svc(): AtlasMaestroRepeatedFailureFastPathRouter
    {
        return new AtlasMaestroRepeatedFailureFastPathRouter;
    }

    private function route(array $overrides = []): array
    {
        return $this->svc()->route($overrides + [
            'operator_only' => false,
            'give_back_count' => 0,
            'give_back_reasons' => [],
            'poison_risk' => 'low',
            'scope_repair_done' => false,
            'dependency_state' => 'ok',
            'gate_failure_count' => 0,
        ]);
    }

    // ── normal_serve ──────────────────────────────────────────────────────────

    public function test_clean_packet_routes_to_normal_serve(): void
    {
        $r = $this->route();

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_NORMAL, $r['lane']);
        $this->assertEqualsWithDelta(0.95, $r['confidence'], 0.01);
    }

    public function test_first_time_failure_low_count_is_normal_serve(): void
    {
        $r = $this->route(['give_back_count' => 1]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_NORMAL, $r['lane']);
        $this->assertEqualsWithDelta(0.70, $r['confidence'], 0.01);
    }

    // ── operator_only_lane ────────────────────────────────────────────────────

    public function test_operator_only_flag_routes_to_operator_only_lane(): void
    {
        $r = $this->route(['operator_only' => true]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_OPERATOR_ONLY, $r['lane']);
        $this->assertEqualsWithDelta(0.98, $r['confidence'], 0.01);
    }

    public function test_operator_only_takes_priority_over_retire_threshold(): void
    {
        $r = $this->route(['operator_only' => true, 'give_back_count' => 10]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_OPERATOR_ONLY, $r['lane']);
    }

    // ── retire_lane ───────────────────────────────────────────────────────────

    public function test_high_give_back_count_routes_to_retire(): void
    {
        $r = $this->route(['give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RETIRE, $r['lane']);
        $this->assertEqualsWithDelta(0.95, $r['confidence'], 0.01);
    }

    public function test_poison_risk_high_routes_to_retire(): void
    {
        $r = $this->route(['poison_risk' => 'high']);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RETIRE, $r['lane']);
        $this->assertEqualsWithDelta(0.85, $r['confidence'], 0.01);
    }

    // ── unblock_lane ──────────────────────────────────────────────────────────

    public function test_repeated_give_back_with_stale_dependency_routes_to_unblock(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_REPEAT_THRESHOLD,
            'dependency_state' => 'stale',
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_UNBLOCK, $r['lane']);
        $this->assertEqualsWithDelta(0.90, $r['confidence'], 0.01);
    }

    public function test_repeated_give_back_with_missing_dependency_routes_to_unblock(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_REPEAT_THRESHOLD,
            'dependency_state' => 'missing',
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_UNBLOCK, $r['lane']);
    }

    // ── rescope_lane ──────────────────────────────────────────────────────────

    public function test_repeated_give_back_with_scope_repair_done_routes_to_rescope(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_REPEAT_THRESHOLD,
            'scope_repair_done' => true,
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RESCOPE, $r['lane']);
        $this->assertEqualsWithDelta(0.88, $r['confidence'], 0.01);
    }

    public function test_repeated_give_back_with_scope_reason_routes_to_rescope(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_REPEAT_THRESHOLD,
            'give_back_reasons' => ['bad_acceptance_criteria', 'other'],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RESCOPE, $r['lane']);
    }

    public function test_repeated_gate_failures_routes_to_rescope(): void
    {
        $r = $this->route([
            'gate_failure_count' => AtlasMaestroRepeatedFailureFastPathRouter::GATE_REPEAT_THRESHOLD,
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RESCOPE, $r['lane']);
        $this->assertEqualsWithDelta(0.80, $r['confidence'], 0.01);
    }

    // ── fast_path_action mapping ─────────────────────────────────────────────

    public function test_fast_path_action_mirrors_lane_for_each_scenario(): void
    {
        $normal = $this->route();
        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_KEEP_SERVING, $normal['fast_path_action']);

        $retire = $this->route(['give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD]);
        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RETIRE, $retire['fast_path_action']);

        $rescope = $this->route(['gate_failure_count' => AtlasMaestroRepeatedFailureFastPathRouter::GATE_REPEAT_THRESHOLD]);
        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RESCOPE, $rescope['fast_path_action']);

        $unblock = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_REPEAT_THRESHOLD,
            'dependency_state' => 'stale',
        ]);
        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_UNBLOCK, $unblock['fast_path_action']);

        $operatorOnly = $this->route(['operator_only' => true]);
        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RETIRE, $operatorOnly['fast_path_action']);
    }

    // ── forbidden self-target / test-only missing implementation ────────────

    public function test_forbidden_self_target_does_not_keep_serving_after_give_back(): void
    {
        $r = $this->route(['forbidden_self_target' => true, 'give_back_count' => 1]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RESCOPE, $r['fast_path_action']);
        $this->assertNotEmpty($r['repair_hint']);
    }

    public function test_test_only_missing_implementation_does_not_keep_serving_after_give_back(): void
    {
        $r = $this->route(['test_only_missing_implementation' => true, 'give_back_count' => 1]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RESCOPE, $r['fast_path_action']);
        $this->assertNotEmpty($r['repair_hint']);
    }

    public function test_forbidden_self_target_with_zero_give_backs_still_keeps_serving(): void
    {
        $r = $this->route(['forbidden_self_target' => true, 'give_back_count' => 0]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_KEEP_SERVING, $r['fast_path_action']);
    }

    // ── contradictory acceptance ─────────────────────────────────────────────

    public function test_contradictory_acceptance_routes_to_rescope_with_acceptance_repair_hint(): void
    {
        $r = $this->route(['contradictory_acceptance' => true]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RESCOPE, $r['fast_path_action']);
        $this->assertSame('contradictory_acceptance', $r['reason']);
        $this->assertStringContainsString('acceptance', $r['repair_hint']);
        $this->assertStringNotContainsString('generic retry', $r['repair_hint']);
    }

    // ── worker-specific vs packet-global poison ──────────────────────────────

    public function test_single_worker_responsible_for_all_give_backs_rescopes_instead_of_retires(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD,
            'worker_give_back_counts' => ['worker-x' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RESCOPE, $r['fast_path_action']);
        $this->assertStringContainsString('single_worker_responsible', $r['reason']);
    }

    public function test_multiple_workers_sharing_give_backs_still_retires(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD,
            'worker_give_back_counts' => ['worker-x' => 4, 'worker-y' => 4],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::ACTION_RETIRE, $r['fast_path_action']);
    }

    // ── schema & structure ────────────────────────────────────────────────────

    public function test_forbidden_self_target_includes_respec_fields_and_stops_serving(): void
    {
        $result = $this->route(['forbidden_self_target' => true, 'give_back_count' => 1]);

        $this->assertContains('allowed_files', $result['respec_fields']);
        $this->assertTrue($result['stop_serving_until_respec']);
    }

    public function test_test_only_missing_implementation_includes_respec_fields_and_stops_serving(): void
    {
        $result = $this->route(['test_only_missing_implementation' => true, 'give_back_count' => 1]);

        $this->assertContains('allowed_files', $result['respec_fields']);
        $this->assertTrue($result['stop_serving_until_respec']);
    }

    public function test_contradictory_acceptance_includes_respec_fields_and_stops_serving(): void
    {
        $result = $this->route(['contradictory_acceptance' => true]);

        $this->assertContains('acceptance_criteria', $result['respec_fields']);
        $this->assertTrue($result['stop_serving_until_respec']);
    }

    public function test_normal_serve_keeps_serving(): void
    {
        $result = $this->route([]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_NORMAL, $result['lane']);
        $this->assertFalse($result['stop_serving_until_respec']);
    }

    public function test_worker_mismatch_retry_keeps_serving(): void
    {
        $result = $this->route([
            'give_back_count' => 8,
            'worker_give_back_counts' => ['worker-a' => 8],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RESCOPE, $result['lane']);
        $this->assertFalse($result['stop_serving_until_respec']);
    }

    public function test_retire_lane_stops_serving(): void
    {
        $result = $this->route([
            'give_back_count' => 8,
            'worker_give_back_counts' => ['worker-a' => 4, 'worker-b' => 4],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::LANE_RETIRE, $result['lane']);
        $this->assertTrue($result['stop_serving_until_respec']);
    }

    public function test_schema_version_always_present(): void
    {
        $r = $this->route();

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::SCHEMA, $r['schema_version']);
    }

    public function test_reason_always_present_and_non_empty(): void
    {
        foreach (['normal', 'retire', 'rescope', 'unblock', 'operator_only'] as $scenario) {
            $packet = match ($scenario) {
                'normal' => [],
                'retire' => ['give_back_count' => 8],
                'rescope' => ['gate_failure_count' => 3],
                'unblock' => ['give_back_count' => 3, 'dependency_state' => 'stale'],
                'operator_only' => ['operator_only' => true],
            };
            $r = $this->route($packet);
            $this->assertNotEmpty($r['reason'], "reason empty for scenario: $scenario");
        }
    }

    // ── AC2: evidence class separates transient / poison / blocked-scope / low-quality-spec ──

    public function test_normal_serve_is_classified_as_transient(): void
    {
        $r = $this->route();

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::EVIDENCE_TRANSIENT, $r['evidence_class']);
    }

    public function test_poison_risk_high_is_classified_as_deterministic_poison(): void
    {
        $r = $this->route(['poison_risk' => 'high']);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::EVIDENCE_DETERMINISTIC_POISON, $r['evidence_class']);
    }

    public function test_forbidden_self_target_is_classified_as_blocked_scope(): void
    {
        $r = $this->route(['forbidden_self_target' => true, 'give_back_count' => 1]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::EVIDENCE_BLOCKED_SCOPE, $r['evidence_class']);
    }

    public function test_contradictory_acceptance_is_classified_as_low_quality_spec(): void
    {
        $r = $this->route(['contradictory_acceptance' => true]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::EVIDENCE_LOW_QUALITY_SPEC, $r['evidence_class']);
    }

    public function test_single_worker_responsible_is_classified_as_transient_not_poison(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD,
            'worker_give_back_counts' => ['worker-x' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD],
        ]);

        $this->assertSame(AtlasMaestroRepeatedFailureFastPathRouter::EVIDENCE_TRANSIENT, $r['evidence_class']);
    }

    // ── AC3: recommended_handling is one of retry/reshape/quarantine/specialist_review ──

    public function test_normal_serve_recommends_retry(): void
    {
        $r = $this->route();

        $this->assertSame('retry', $r['recommended_handling']);
    }

    public function test_low_quality_spec_recommends_reshape(): void
    {
        $r = $this->route(['contradictory_acceptance' => true]);

        $this->assertSame('reshape', $r['recommended_handling']);
    }

    public function test_deterministic_poison_recommends_quarantine(): void
    {
        $r = $this->route(['poison_risk' => 'high']);

        $this->assertSame('quarantine', $r['recommended_handling']);
    }

    public function test_operator_only_recommends_specialist_review(): void
    {
        $r = $this->route(['operator_only' => true]);

        $this->assertSame('specialist_review', $r['recommended_handling']);
    }

    // ── AC4: token-burn prevention reason present exactly when blocking a blind retry ──

    public function test_normal_serve_has_no_token_burn_prevention_reason(): void
    {
        $r = $this->route();

        $this->assertFalse($r['stop_serving_until_respec']);
        $this->assertNull($r['token_burn_prevention_reason']);
    }

    public function test_retire_lane_has_token_burn_prevention_reason(): void
    {
        $r = $this->route(['give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD]);

        $this->assertTrue($r['stop_serving_until_respec']);
        $this->assertNotEmpty($r['token_burn_prevention_reason']);
        $this->assertStringContainsString('token burn', $r['token_burn_prevention_reason']);
    }

    public function test_single_worker_responsible_keeps_serving_and_has_no_token_burn_reason(): void
    {
        $r = $this->route([
            'give_back_count' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD,
            'worker_give_back_counts' => ['worker-x' => AtlasMaestroRepeatedFailureFastPathRouter::GIVE_BACK_RETIRE_THRESHOLD],
        ]);

        $this->assertFalse($r['stop_serving_until_respec']);
        $this->assertNull($r['token_burn_prevention_reason']);
    }
}
