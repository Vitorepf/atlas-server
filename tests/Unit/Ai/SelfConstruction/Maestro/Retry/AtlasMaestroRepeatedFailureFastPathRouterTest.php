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

    // ── schema & structure ────────────────────────────────────────────────────

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
}
