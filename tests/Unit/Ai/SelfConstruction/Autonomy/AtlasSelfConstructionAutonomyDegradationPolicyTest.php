<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyDegradationPolicy;
use Tests\TestCase;

class AtlasSelfConstructionAutonomyDegradationPolicyTest extends TestCase
{
    public function test_no_signal_returns_no_action(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_NO_ACTION, $verdict['action']);
        self::assertSame('no_degradation_signal', $verdict['reason']);
        self::assertFalse($verdict['widens_scope']);
        self::assertFalse($verdict['relaxes_gates']);
    }

    public function test_false_green_demands_rollback(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['false_green_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_ROLLBACK_REQUIRED, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_FALSE_GREEN, $verdict['reason']);
        self::assertFalse($verdict['widens_scope']);
    }

    public function test_rollback_failure_pauses(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([
            'rollback_failure_detected' => true,
            'false_green_detected' => true,
        ]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_PAUSE, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_ROLLBACK_FAILURE, $verdict['reason']);
        self::assertFalse($verdict['widens_scope']);
    }

    public function test_repeated_poison_packet_triggers_repair_first(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([
            'repeated_poison_packet_count' => AtlasSelfConstructionAutonomyDegradationPolicy::REPEATED_POISON_THRESHOLD,
        ]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_REPAIR_FIRST, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_REPEATED_POISON, $verdict['reason']);
    }

    public function test_below_repeated_poison_threshold_returns_no_action(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([
            'repeated_poison_packet_count' => AtlasSelfConstructionAutonomyDegradationPolicy::REPEATED_POISON_THRESHOLD - 1,
        ]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_NO_ACTION, $verdict['action']);
    }

    public function test_missing_evidence_downgrades(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['missing_evidence_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_DOWNGRADE, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_MISSING_EVIDENCE, $verdict['reason']);
    }

    public function test_queue_jam_triggers_repair_first(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['queue_jam_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_REPAIR_FIRST, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_QUEUE_JAM, $verdict['reason']);
    }

    public function test_cost_or_risk_breach_downgrades(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['cost_or_risk_breach_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_DOWNGRADE, $verdict['action']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_COST_OR_RISK_BREACH, $verdict['reason']);
    }

    public function test_receipt_observed_facts_mirrors_input_signals(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([
            'missing_evidence_detected' => true,
            'repeated_poison_packet_count' => 1,
        ]);

        $this->assertArrayHasKey('observed_facts', $verdict);
        $this->assertArrayHasKey('schema_version', $verdict);
        $this->assertTrue($verdict['observed_facts']['missing_evidence_detected']);
        $this->assertSame(1, $verdict['observed_facts']['repeated_poison_packet_count']);
        $this->assertFalse($verdict['observed_facts']['false_green_detected']);
        $this->assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::SCHEMA, $verdict['schema_version']);
    }

    public function test_bounded_cooldown_rollback_failure_dominates_all_concurrent_signals(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([
            'rollback_failure_detected' => true,
            'false_green_detected' => true,
            'queue_jam_detected' => true,
            'missing_evidence_detected' => true,
            'cost_or_risk_breach_detected' => true,
            'repeated_poison_packet_count' => 99,
        ]);

        $this->assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_PAUSE, $verdict['action']);
        $this->assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_ROLLBACK_FAILURE, $verdict['reason']);
    }

    // ── AC: fallback_mode / trigger / lost_capability / preserved_capability / recovery_condition / receipt ──

    public function test_provider_outage_downgrades_with_local_fallback_receipt(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['provider_outage_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_DOWNGRADE, $verdict['fallback_mode']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_PROVIDER_OUTAGE, $verdict['trigger']);
        self::assertSame('local_atlas_native_execution', $verdict['preserved_capability']);
        self::assertNotEmpty($verdict['lost_capability']);
        self::assertNotEmpty($verdict['recovery_condition']);
        self::assertStringContainsString('fallback_mode=downgrade', $verdict['receipt']);
    }

    public function test_queue_corruption_via_queue_jam_repairs_first_with_receipt(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['queue_jam_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_REPAIR_FIRST, $verdict['fallback_mode']);
        self::assertSame('in_flight_task_completion', $verdict['preserved_capability']);
        self::assertNotEmpty($verdict['receipt']);
    }

    public function test_high_false_green_risk_requires_rollback_with_preserved_replay_capability(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['false_green_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_ROLLBACK_REQUIRED, $verdict['fallback_mode']);
        self::assertSame('rollback_and_evidence_replay_capability', $verdict['preserved_capability']);
        self::assertStringContainsString('rollback_verified', $verdict['recovery_condition']);
    }

    public function test_stale_context_downgrades_with_local_fallback(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide(['stale_context_detected' => true]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_DOWNGRADE, $verdict['fallback_mode']);
        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::REASON_STALE_CONTEXT, $verdict['trigger']);
        self::assertSame('local_atlas_native_execution', $verdict['preserved_capability']);
    }

    public function test_clean_no_degradation_state_preserves_full_autonomy(): void
    {
        $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([]);

        self::assertSame(AtlasSelfConstructionAutonomyDegradationPolicy::ACTION_NO_ACTION, $verdict['fallback_mode']);
        self::assertSame('none', $verdict['lost_capability']);
        self::assertSame('full_autonomous_execution', $verdict['preserved_capability']);
        self::assertArrayHasKey('receipt', $verdict);
    }

    public function test_policy_never_widens_scope_or_relaxes_gates_under_any_signal(): void
    {
        $signals = [
            'false_green_detected' => true,
            'rollback_failure_detected' => true,
            'missing_evidence_detected' => true,
            'queue_jam_detected' => true,
            'cost_or_risk_breach_detected' => true,
            'repeated_poison_packet_count' => 99,
        ];
        foreach ($signals as $key => $value) {
            $verdict = (new AtlasSelfConstructionAutonomyDegradationPolicy)->decide([$key => $value]);
            self::assertFalse($verdict['widens_scope'], "widens_scope under signal {$key} must remain false");
            self::assertFalse($verdict['relaxes_gates'], "relaxes_gates under signal {$key} must remain false");
        }
    }
}
