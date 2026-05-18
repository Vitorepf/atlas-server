<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the gate logic. We feed a fake metrics service
 * that returns canned snapshots, so the gate's branching can be
 * exercised without touching the DB.
 */
final class VoxV3PromotionGateServiceTest extends TestCase
{
    private function gateWithSnapshot(array $snapshot): VoxV3PromotionGateService
    {
        $metrics = new class($snapshot) extends VoxMetricsService {
            public function __construct(private readonly array $stub) {}

            public function snapshot(): array
            {
                return $this->stub;
            }
        };

        return new VoxV3PromotionGateService($metrics);
    }

    private function snapshotWithSafetyOk(int $sessions = 0, int $days = 0): array
    {
        return [
            'schema' => VoxMetricsService::SCHEMA,
            'status' => 'ok',
            'summary' => [
                'total_sessions' => $sessions,
                'real_usage_days' => $days,
                'average_sessions_per_day' => 0.0,
                'first_session_at' => null,
                'last_session_at' => null,
                'dictionary_correction_count' => 0,
                'stt_wer_estimate' => null,
            ],
            'modes' => [],
            'safety' => [
                'raw_audio_persisted_count' => 0,
                'confirmation_bypass_count' => 0,
                'destructive_action_without_receipt' => 0,
                'eclipse_test_success_count' => 3,
                'governed_execute_success_count' => 0,
                'governed_execute_blocked_count' => 0,
            ],
            'rivals' => [],
            'hard_gates' => [
                'raw_audio_persisted_count' => 0,
                'confirmation_bypass_count' => 0,
                'destructive_action_without_receipt' => 0,
                'eclipse_test_success_count' => 3,
                'action_regret_score' => 0.0,
                'prompt_quality_delta' => 0.0,
                'rivals_voice_multiplier' => 0.0,
            ],
            'generated_at' => '2026-05-20T00:00:00Z',
        ];
    }

    public function test_blocked_when_eclipse_never_tested(): void
    {
        $snap = $this->snapshotWithSafetyOk();
        $snap['hard_gates']['eclipse_test_success_count'] = 0;
        $snap['safety']['eclipse_test_success_count'] = 0;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_BLOCKED, $eval['status']);
        $this->assertContains('eclipse_test_success_min', $eval['blockers']);
        $this->assertTrue($eval['explicit_vitor_approval_required']);
    }

    public function test_blocked_when_raw_audio_persisted_above_zero(): void
    {
        $snap = $this->snapshotWithSafetyOk();
        $snap['hard_gates']['raw_audio_persisted_count'] = 1;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_BLOCKED, $eval['status']);
        $this->assertContains('raw_audio_persisted_zero', $eval['blockers']);
    }

    public function test_blocked_when_confirmation_bypass_above_zero(): void
    {
        $snap = $this->snapshotWithSafetyOk();
        $snap['hard_gates']['confirmation_bypass_count'] = 1;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_BLOCKED, $eval['status']);
        $this->assertContains('confirmation_bypass_zero', $eval['blockers']);
    }

    public function test_blocked_when_destructive_without_receipt_above_zero(): void
    {
        $snap = $this->snapshotWithSafetyOk();
        $snap['hard_gates']['destructive_action_without_receipt'] = 1;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_BLOCKED, $eval['status']);
        $this->assertContains('destructive_action_without_receipt_zero', $eval['blockers']);
    }

    public function test_warming_up_when_safety_clean_but_usage_short(): void
    {
        $snap = $this->snapshotWithSafetyOk(sessions: 0, days: 0);
        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_WARMING_UP, $eval['status']);
        $this->assertContains('usage_window', $eval['warming_up']);
    }

    public function test_ready_for_vitor_review_when_all_hard_and_soft_gates_pass(): void
    {
        $snap = $this->snapshotWithSafetyOk(sessions: 120, days: 35);
        $snap['hard_gates']['prompt_quality_delta'] = 0.4;
        $snap['hard_gates']['rivals_voice_multiplier'] = 1.5;
        $snap['hard_gates']['action_regret_score'] = 0.02;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_READY, $eval['status']);
        $this->assertSame([], $eval['blockers']);
        $this->assertTrue($eval['explicit_vitor_approval_required']);
    }

    public function test_usage_window_passes_via_session_count_alone(): void
    {
        $snap = $this->snapshotWithSafetyOk(sessions: 100, days: 0);
        $snap['hard_gates']['prompt_quality_delta'] = 0.4;
        $snap['hard_gates']['rivals_voice_multiplier'] = 1.5;

        $eval = $this->gateWithSnapshot($snap)->evaluate();
        $this->assertSame(VoxV3PromotionGateService::STATUS_READY, $eval['status']);
    }
}
