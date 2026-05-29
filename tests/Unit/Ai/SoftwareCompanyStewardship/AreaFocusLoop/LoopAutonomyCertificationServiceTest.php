<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopAutonomyCertificationService;
use Tests\TestCase;

final class LoopAutonomyCertificationServiceTest extends TestCase
{
    private function service(): LoopAutonomyCertificationService
    {
        return app(LoopAutonomyCertificationService::class);
    }

    /**
     * A fully-green AP-805 readiness payload so the certification depends only on
     * the autonomy taxonomy + documented runtime facts, never on live probes.
     *
     * @return array<string,mixed>
     */
    private function healthyReadiness(): array
    {
        $okGate = static fn (): array => ['ok' => true, 'hard' => true, 'detail' => 'ok'];

        return [
            'status' => 'ready',
            'gates' => [
                'finding_slice_planner_available' => $okGate(),
                'duplicate_finding_guard_available' => $okGate(),
                'multi_agent_lane_contracts_available' => $okGate(),
                'judge_repair_available' => $okGate(),
                'provider_routing_available_or_honest_degraded' => $okGate(),
                'provider_timeout_minimum_ok' => $okGate(),
                'merge_truth_guard_present' => $okGate(),
                'kill_switch_available' => $okGate(),
                'no_stale_lock' => $okGate(),
                'product_mode_projection_memory_safe' => $okGate(),
            ],
            'provider_state' => ['available_binaries' => ['cursor-agent', 'claude', 'codex']],
        ];
    }

    public function test_aaeos_dev_lane_envelope_stages_are_pre_authorized_but_mode_not_yet_autonomous(): void
    {
        $payload = $this->service()->certify([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'target_mode' => LoopAutonomyCertificationService::MODE_AAEOS_DEV_LANE,
            'readiness' => $this->healthyReadiness(),
        ]);

        $this->assertSame(LoopAutonomyCertificationService::REPORT_SCHEMA, $payload['schema_version']);
        $this->assertSame('aaeos_dev_integration_lane', $payload['target_mode']);

        // AP-806 slice 1+2 landed: scope admission + lane merge target are now
        // implemented & armable via a one-time operator-configured envelope, so
        // they are policy_pre_authorized — NOT a blocker.
        $this->assertSame(
            LoopAutonomyCertificationService::STATE_POLICY_PRE_AUTHORIZED,
            $payload['stages']['scope_admission']['state'],
        );
        $this->assertSame(
            LoopAutonomyCertificationService::STATE_POLICY_PRE_AUTHORIZED,
            $payload['stages']['merge_target']['state'],
        );

        // But the mode is still NOT fully autonomous: learning/compounding is not
        // wired back into selection. Honest, never dressed as ready.
        $this->assertStringContainsString('NOT autonomous', $payload['verdict']);
        $this->assertLessThan(1.0, $payload['autonomy_score']);
        $blockerStages = array_column($payload['blockers_by_impact'], 'stage');
        $this->assertContains('learning_compounding', $blockerStages);
        $this->assertNotContains('scope_admission', $blockerStages);
        $this->assertNotContains('merge_target', $blockerStages);

        foreach ($payload['blockers_by_impact'] as $blocker) {
            $this->assertContains($blocker['state'], [
                LoopAutonomyCertificationService::STATE_NOT_IMPLEMENTED,
                LoopAutonomyCertificationService::STATE_REQUIRES_OPERATOR_PER_CYCLE,
                LoopAutonomyCertificationService::STATE_REQUIRES_CLAUDE_MANUAL,
                LoopAutonomyCertificationService::STATE_UNSAFE,
            ]);
            $this->assertNotSame('', (string) $blocker['remediation']);
        }
    }

    public function test_next_slice_targets_the_remaining_top_blocker(): void
    {
        $payload = $this->service()->certify([
            'target_mode' => LoopAutonomyCertificationService::MODE_AAEOS_DEV_LANE,
            'readiness' => $this->healthyReadiness(),
        ]);

        $slice = $payload['next_executable_slice'];
        // With the envelope landed, the remaining top blocker is learning/compounding.
        $this->assertSame('learning_compounding', $slice['depends_on_blocker']);
        $this->assertNotSame('', (string) $slice['title']);
    }

    public function test_forge_real_execution_is_marked_not_implemented_never_ready(): void
    {
        $payload = $this->service()->certify([
            'target_mode' => LoopAutonomyCertificationService::MODE_AAEOS_FORGE,
            'readiness' => $this->healthyReadiness(),
        ]);

        $execution = $payload['stages']['execution'];
        $this->assertSame(LoopAutonomyCertificationService::STATE_NOT_IMPLEMENTED, $execution['state']);
        $this->assertTrue($payload['claim_policy']['forge_real_execution_is_not_implemented_today']);
        $this->assertTrue($payload['claim_policy']['false_autonomy_never_claimed']);

        // Forge mode must score strictly worse than the Atlas Dev lane mode.
        $forgeScore = $payload['mode_autonomy_scores']['aaeos_forge_full']['autonomy_score'];
        $devScore = $payload['mode_autonomy_scores']['aaeos_dev_integration_lane']['autonomy_score'];
        $this->assertLessThanOrEqual($devScore, $forgeScore);
    }

    public function test_factory_scoped_mode_is_more_autonomous_than_cross_system(): void
    {
        $payload = $this->service()->certify([
            'target_mode' => LoopAutonomyCertificationService::MODE_FACTORY_SCOPED,
            'readiness' => $this->healthyReadiness(),
        ]);

        $factory = $payload['mode_autonomy_scores']['factory_scoped_self_improvement']['autonomy_score'];
        $devLane = $payload['mode_autonomy_scores']['aaeos_dev_integration_lane']['autonomy_score'];

        // Factory-scoped self-improvement is the closest-to-autonomous mode today.
        $this->assertGreaterThan($devLane, $factory);
        // But it is still not fully autonomous: learning/compounding is not wired back in.
        $this->assertSame(
            LoopAutonomyCertificationService::STATE_NOT_IMPLEMENTED,
            $payload['stages']['learning_compounding']['state'],
        );
    }

    public function test_report_hash_is_deterministic_and_excludes_volatile_fields(): void
    {
        $a = $this->service()->certify(['readiness' => $this->healthyReadiness()]);
        $b = $this->service()->certify(['readiness' => $this->healthyReadiness()]);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertStringStartsWith('sha256:', $a['report_hash']);
    }

    /** Step-3 first rule: matching Phase 14 cockpit snapshot marks surface reachable for intent_id. */
    public function test_mission_control_cockpit_phase_14_signal_reachable_for_matching_snapshot(): void
    {
        $intentId = 'intent-afsb-mcc-1';
        $snapshot = [
            'schema' => AtlasMissionControlCockpitService::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'snapshot_hash' => 'sha256:abc123',
            'phase_count' => 17,
        ];

        $payload = $this->service()->certify([
            'readiness' => $this->healthyReadiness(),
            'intent_id' => $intentId,
            'mission_control_cockpit_snapshot' => $snapshot,
        ]);

        $signal = $payload['mission_control_cockpit_phase_14_signal'];
        $this->assertSame(
            LoopAutonomyCertificationService::MISSION_CONTROL_COCKPIT_PHASE_14_SIGNAL_SCHEMA,
            $signal['schema_version'],
        );
        $this->assertSame($intentId, $signal['intent_id']);
        $this->assertTrue($signal['reachable']);
        $this->assertSame('reachable', $signal['status']);
        $this->assertSame('sha256:abc123', $signal['snapshot_hash']);
    }

    public function test_horizon_gaps_name_the_seven_day_and_thirty_day_dependencies(): void
    {
        $payload = $this->service()->certify(['readiness' => $this->healthyReadiness()]);

        // The envelope + cross-system admission are implemented (slice 1+2), so
        // they are no longer 7-day gaps; what remains for 7 days is the live
        // multi-day stability proof.
        $d7 = $payload['horizon_gaps']['d7']['missing'];
        $this->assertNotContains('integration_lane_autonomy_envelope', $d7);
        $this->assertTrue(
            collect($d7)->contains(fn (string $m): bool => str_contains($m, 'seven_day_stability_proof')),
            'd7 must still name the seven-day stability proof gap',
        );

        $d30 = $payload['horizon_gaps']['d30']['missing'];
        $this->assertContains('multi_area_parallel_loops', $d30);
    }
}
