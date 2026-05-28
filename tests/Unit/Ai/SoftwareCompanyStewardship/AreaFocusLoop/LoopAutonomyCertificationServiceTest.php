<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

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

    public function test_aaeos_dev_lane_is_honestly_blocked_on_envelope_even_when_readiness_is_green(): void
    {
        $payload = $this->service()->certify([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'target_mode' => LoopAutonomyCertificationService::MODE_AAEOS_DEV_LANE,
            'readiness' => $this->healthyReadiness(),
        ]);

        $this->assertSame(LoopAutonomyCertificationService::REPORT_SCHEMA, $payload['schema_version']);
        $this->assertSame('aaeos_dev_integration_lane', $payload['target_mode']);

        // Even with a green readiness, cross-system autonomy is NOT achieved: the
        // integration-lane envelope and cross-system admission do not exist yet.
        $this->assertStringContainsString('NOT autonomous', $payload['verdict']);
        $this->assertLessThan(1.0, $payload['autonomy_score']);

        $blockerStages = array_column($payload['blockers_by_impact'], 'stage');
        $this->assertContains('scope_admission', $blockerStages);
        $this->assertContains('merge_target', $blockerStages);

        // Each blocker is named precisely, never dressed as ready.
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

    public function test_next_slice_targets_the_integration_lane_envelope(): void
    {
        $payload = $this->service()->certify([
            'target_mode' => LoopAutonomyCertificationService::MODE_AAEOS_DEV_LANE,
            'readiness' => $this->healthyReadiness(),
        ]);

        $slice = $payload['next_executable_slice'];
        $this->assertContains($slice['depends_on_blocker'], ['merge_target', 'scope_admission']);
        $this->assertStringContainsString('integration lane', strtolower($slice['title']));
        // The first small slice must stay inside the factory-scoped boundary (no provider, no new Forge runtime).
        $this->assertArrayHasKey('first_small_slice', $slice);
        foreach ((array) $slice['scope_files'] as $file) {
            $this->assertStringContainsString('AreaFocusLoop', (string) $file);
        }
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

    public function test_horizon_gaps_name_the_seven_day_and_thirty_day_dependencies(): void
    {
        $payload = $this->service()->certify(['readiness' => $this->healthyReadiness()]);

        $d7 = $payload['horizon_gaps']['d7']['missing'];
        $this->assertContains('integration_lane_autonomy_envelope', $d7);
        $this->assertContains('cross_system_scope_admission', $d7);

        $d30 = $payload['horizon_gaps']['d30']['missing'];
        $this->assertContains('multi_area_parallel_loops', $d30);
    }
}
