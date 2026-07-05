<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialSpecReviewBoard;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierCanaryKillSwitch;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBehaviorLockPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopReadinessGate;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopSafetyGateRunner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainClosedLoopSafetyGateRunnerTest extends TestCase
{
    private function fullyArmedRunner(): AtlasExternalBrainClosedLoopSafetyGateRunner
    {
        return new AtlasExternalBrainClosedLoopSafetyGateRunner(
            readinessGate: new AtlasExternalBrainClosedLoopReadinessGate,
            canaryKillSwitch: new AtlasExternalBrainAmplifierCanaryKillSwitch,
            behaviorLockPlanner: new AtlasExternalBrainBehaviorLockPlanner,
            adversarialSpecReviewBoard: new AtlasExternalBrainAdversarialSpecReviewBoard,
        );
    }

    private function emptyRunner(): AtlasExternalBrainClosedLoopSafetyGateRunner
    {
        return new AtlasExternalBrainClosedLoopSafetyGateRunner;
    }

    /** @return array<string, array<string,mixed>> */
    private function wiredDossier(): array
    {
        $link = fn (string $evidence): array => [
            'evidence' => $evidence,
            'hash' => hash('sha256', $evidence),
        ];

        return [
            'dossier' => [
                'proposal' => $link('proposal evidence'),
                'task_fabric' => $link('task_fabric evidence'),
                'queue_admission' => $link('queue_admission evidence'),
                'muscle_outcome' => $link('muscle_outcome evidence'),
                'learning_update' => $link('learning_update evidence'),
                'resequencing' => $link('resequencing evidence'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function healthyCanaryMetrics(): array
    {
        return [
            'sample_size' => 15,
            'duplicate_rate' => 0.0,
            'give_back_rate' => 0.0,
            'malformed_rate' => 0.0,
            'weak_evidence_rate' => 0.0,
            'low_value_rate' => 0.0,
            'proxy_leak_rate' => 0.0,
            'held_out_failure_streak' => 0,
            'false_green_rate' => 0.0,
            'rollback_telemetry' => false,
            'regression_spike' => false,
            'has_mandatory_telemetry' => true,
            'was_previously_rolled_back' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function unarmedCanaryMetrics(): array
    {
        return [
            'sample_size' => 15,
            'duplicate_rate' => 0.0,
            'give_back_rate' => 0.0,
            'malformed_rate' => 0.0,
            'weak_evidence_rate' => 0.0,
            'low_value_rate' => 0.0,
            'proxy_leak_rate' => 0.0,
            'held_out_failure_streak' => 0,
            'false_green_rate' => 0.0,
            'rollback_telemetry' => true,  // triggers kill switch → rollback
            'regression_spike' => false,
            'has_mandatory_telemetry' => true,
            'was_previously_rolled_back' => false,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function safeBehaviorTargets(): array
    {
        return [[
            'target_id' => 'organ-1',
            'requested_action' => 'keep',
            'behavior_bearing' => false,
        ]];
    }

    /** @return array<string, mixed> */
    private function approvedTaskSpec(): array
    {
        return [
            'task_packet_id' => 'packet-1',
            'objective' => 'implement a new closed-loop safety gate',
            'allowed_files' => ['app/Services/SafetyGate.php', 'tests/Unit/SafetyGateTest.php'],
            'acceptance_criteria' => ['phpunit --filter=SafetyGateTest asserts the safety gate returns correct verdict'],
            'required_evidence' => ['phpunit pass receipt'],
        ];
    }

    // ── fully-armed safe loop: all gates pass ────────────────────────────────

    public function test_fully_armed_safe_loop_passes_when_all_gates_approve(): void
    {
        $runner = $this->fullyArmedRunner();

        $result = $runner->run([
            'readiness_dossier' => $this->wiredDossier(),
            'canary_metrics' => $this->healthyCanaryMetrics(),
            'behavior_targets' => $this->safeBehaviorTargets(),
            'task_spec' => $this->approvedTaskSpec(),
        ]);

        $this->assertTrue($result['safe']);
        $this->assertSame([], $result['reasons']);
        $this->assertSame(
            AtlasExternalBrainClosedLoopSafetyGateRunner::SCHEMA,
            $result['schema'],
        );
        // Each sub-verdict is present
        $this->assertTrue($result['readiness']['ready']);
        $this->assertSame('continue', $result['canary']['action']);
        $this->assertSame(0, $result['behavior_locks']['lock_first_count']);
        $this->assertTrue($result['review']['approved']);
    }

    // ── unarmed canary fails: rollback_telemetry triggers kill switch ─────────

    public function test_unarmed_canary_fails_when_kill_switch_triggered(): void
    {
        $runner = $this->fullyArmedRunner();

        $result = $runner->run([
            'readiness_dossier' => $this->wiredDossier(),
            'canary_metrics' => $this->unarmedCanaryMetrics(),
            'behavior_targets' => $this->safeBehaviorTargets(),
            'task_spec' => $this->approvedTaskSpec(),
        ]);

        $this->assertFalse($result['safe']);
        $this->assertContains(
            'canary_kill_switch_active:rollback_telemetry',
            $result['reasons'],
        );
        $this->assertSame('rollback', $result['canary']['action']);
    }

    // ── null constructor gracefully degrades ─────────────────────────────────

    public function test_empty_runner_with_no_data_returns_safe_defaults(): void
    {
        $result = $this->emptyRunner()->run([]);

        $this->assertTrue($result['safe']);
        $this->assertSame([], $result['reasons']);
        // Default stubs: readiness marked ready, canary continues, no locks, spec approved
        $this->assertTrue($result['readiness']['ready']);
        $this->assertSame('continue', $result['canary']['action']);
        $this->assertSame(0, $result['behavior_locks']['lock_first_count']);
        $this->assertTrue($result['review']['approved']);
    }

    // ── readiness gate failure blocks safety ─────────────────────────────────

    public function test_readiness_gate_failure_blocks_safety(): void
    {
        $runner = $this->fullyArmedRunner();

        $result = $runner->run([
            'readiness_dossier' => ['dossier' => []],  // all 6 links missing
            'canary_metrics' => $this->healthyCanaryMetrics(),
            'behavior_targets' => $this->safeBehaviorTargets(),
            'task_spec' => $this->approvedTaskSpec(),
        ]);

        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['reasons']);
        $hasReadinessReason = false;
        foreach ($result['reasons'] as $reason) {
            if (str_starts_with($reason, 'closed_loop_readiness_blocked:')) {
                $hasReadinessReason = true;
                break;
            }
        }
        $this->assertTrue($hasReadinessReason, 'Expected a closed_loop_readiness_blocked reason');
    }
}
