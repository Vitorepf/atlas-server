<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEndToEndAutonomyReplayHarness;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyReplayCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'autonomy_replay_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:autonomy-replay', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function healthySnapshotInput(): array
    {
        return [
            'rubric_scores' => array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 0.95),
            'queue_health' => ['status' => 'healthy'],
            'audit_result' => ['verdict' => 'pass', 'findings' => []],
            'ledger_summary' => ['total' => 10, 'success_rate' => 0.9],
            'stalled_yield' => false,
        ];
    }

    private function completeScenario(): array
    {
        return [
            'intake' => ['evidence' => ['e1']],
            'admission' => ['candidate_pool' => ['c1', 'c2']],
            'enqueue_decision' => ['queue_facts' => ['poison_detected' => false, 'sprawl_pressure' => false, 'low_value_ratio' => 0.1]],
            'outcome_learning' => ['recorded' => true],
            'next_action' => ['action' => 'create_more_tasks'],
        ];
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:autonomy-replay');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_complete_cycle_with_healthy_control_plane_is_overall_ready(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:autonomy-replay', ['--input' => $this->writeInput([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => $this->healthySnapshotInput(),
        ])]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($result['overall_ready']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE, $result['autonomy_replay_status']);
        $this->assertSame('none_required_cycle_ready', $result['next_atlas_native_repair_action']);
        $this->assertSame(0, $exitCode);
    }

    public function test_missing_step_surfaces_exact_failed_step_and_repair_hint(): void
    {
        $scenario = $this->completeScenario();
        unset($scenario['outcome_learning']);

        $exitCode = Artisan::call('atlas:external-brain:autonomy-replay', ['--input' => $this->writeInput([
            'scenario' => $scenario,
            'control_plane_snapshot' => $this->healthySnapshotInput(),
        ])]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($result['overall_ready']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_EVIDENCE_MISSING, $result['autonomy_replay_status']);
        $this->assertSame('outcome_learning', $result['failed_step']);
        $this->assertStringContainsString('outcome_learning', $result['next_atlas_native_repair_action']);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_operator_dependency_is_never_fake_ready(): void
    {
        $scenario = $this->completeScenario();
        $scenario['admission']['requires_operator'] = true;

        $result = $this->callCommand([
            'scenario' => $scenario,
            'control_plane_snapshot' => $this->healthySnapshotInput(),
        ]);

        $this->assertFalse($result['overall_ready']);
        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_HUMAN_OR_PROVIDER_DEPENDENCY_DETECTED, $result['autonomy_replay_status']);
        $this->assertSame('admission', $result['failed_step']);
    }

    public function test_provider_steady_state_dependency_is_never_fake_ready(): void
    {
        $scenario = $this->completeScenario();
        $scenario['enqueue_decision']['requires_provider_steady_state'] = true;

        $result = $this->callCommand([
            'scenario' => $scenario,
            'control_plane_snapshot' => $this->healthySnapshotInput(),
        ]);

        $this->assertFalse($result['overall_ready']);
        $this->assertSame('enqueue_decision', $result['failed_step']);
    }

    public function test_cycle_complete_but_weak_control_plane_is_not_overall_ready(): void
    {
        $result = $this->callCommand([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => [],
        ]);

        $this->assertSame(AtlasExternalBrainEndToEndAutonomyReplayHarness::STATUS_CYCLE_COMPLETE, $result['autonomy_replay_status']);
        $this->assertFalse($result['overall_ready']);
        $this->assertNotSame('none_required_cycle_ready', $result['next_atlas_native_repair_action']);
    }

    // ── cycle-replay causality verifier ─────────────────────────────────────

    private function completeCycleReplayFacts(): array
    {
        return [
            ['stage' => 'lever_chosen', 'sequence' => 1, 'lever' => 'create_more_tasks'],
            ['stage' => 'packet_emitted', 'sequence' => 2, 'task_packet_id' => 'pkt-1'],
            ['stage' => 'muscle_outcome', 'sequence' => 3, 'task_packet_id' => 'pkt-1'],
            ['stage' => 'gates_judged', 'sequence' => 4, 'task_packet_id' => 'pkt-1', 'gate_verdict' => 'pass'],
            ['stage' => 'outcome_learning', 'sequence' => 5, 'task_packet_id' => 'pkt-1', 'outcome_learning_ref' => 'ol-1'],
            ['stage' => 'next_decision', 'sequence' => 6, 'lever' => 'consolidate_or_burn_debt'],
        ];
    }

    public function test_complete_causal_cycle_replay_keeps_overall_ready(): void
    {
        $result = $this->callCommand([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => $this->healthySnapshotInput(),
            'cycle_replay_facts' => $this->completeCycleReplayFacts(),
        ]);

        $this->assertTrue($result['overall_ready']);
        $this->assertTrue($result['cycle_replay_verified']);
        $this->assertSame('none_required_cycle_ready', $result['next_atlas_native_repair_action']);
    }

    public function test_incomplete_cycle_replay_facts_block_overall_readiness_even_with_healthy_control_plane(): void
    {
        $facts = $this->completeCycleReplayFacts();
        array_pop($facts); // drop next_decision

        $result = $this->callCommand([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => $this->healthySnapshotInput(),
            'cycle_replay_facts' => $facts,
        ]);

        $this->assertFalse($result['overall_ready']);
        $this->assertFalse($result['cycle_replay_verified']);
        $this->assertSame('next_decision', $result['cycle_replay_missing_stage']);
        $this->assertStringContainsString('cycle_replay_verification_incomplete', $result['next_atlas_native_repair_action']);
    }

    public function test_correlation_mismatch_in_cycle_replay_blocks_overall_readiness(): void
    {
        $facts = $this->completeCycleReplayFacts();
        $facts[2]['task_packet_id'] = 'pkt-DIFFERENT'; // muscle_outcome disagrees with packet_emitted

        $result = $this->callCommand([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => $this->healthySnapshotInput(),
            'cycle_replay_facts' => $facts,
        ]);

        $this->assertFalse($result['overall_ready']);
        $this->assertNotEmpty($result['cycle_replay_causality_violations']);
    }

    public function test_absent_cycle_replay_facts_does_not_regress_prior_ready_behavior(): void
    {
        $result = $this->callCommand([
            'scenario' => $this->completeScenario(),
            'control_plane_snapshot' => $this->healthySnapshotInput(),
        ]);

        $this->assertTrue($result['overall_ready']);
        $this->assertTrue($result['cycle_replay_verified']);
    }
}
