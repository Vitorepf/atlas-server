<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroReplenisherFeedback;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroReplenisherFeedbackTest extends TestCase
{
    private function feedback(): AtlasMaestroReplenisherFeedback
    {
        $miner = new class
        {
            public function mine(): array
            {
                return [];
            }
        };

        return new AtlasMaestroReplenisherFeedback($miner);
    }

    // ── low-depth case ─────────────────────────────────────────────────────

    public function test_low_depth_recommends_replenish(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 2,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_REPLENISH, $r['recommended_originator_mode']);
        $this->assertLessThan(0, $r['queue_delta']);
        $this->assertSame(-18, $r['queue_delta']);
    }

    // ── high-give-back case ────────────────────────────────────────────────

    public function test_high_give_back_recommends_refactor(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 20,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.5,
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_REFACTOR, $r['recommended_originator_mode']);
        $this->assertGreaterThan(0.0, $r['give_back_pressure']);
        $this->assertContains('give_back_pressure_high:0.5', $r['mode_reasons']);
    }

    // ── low-quality case ───────────────────────────────────────────────────

    public function test_low_quality_recommends_refactor(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 20,
            'target_depth' => 20,
            'task_quality_score' => 0.2,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_REFACTOR, $r['recommended_originator_mode']);
        $this->assertGreaterThan(0.0, $r['task_quality_pressure']);
    }

    // ── healthy-depth case ─────────────────────────────────────────────────

    public function test_healthy_depth_recommends_wait(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 22,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_WAIT, $r['recommended_originator_mode']);
    }

    public function test_deep_surplus_healthy_queue_recommends_escalate_ambition(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 50,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_ESCALATE_AMBITION, $r['recommended_originator_mode']);
    }

    // ── self-heal precedence ───────────────────────────────────────────────

    public function test_health_flags_recommend_self_heal_even_with_low_depth(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 2,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.05,
            'health_flags' => ['lease_repo_disconnected'],
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_SELF_HEAL, $r['recommended_originator_mode']);
    }

    public function test_critical_worker_drain_horizon_recommends_self_heal(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 22,
            'target_depth' => 20,
            'task_quality_score' => 0.9,
            'give_back_rate' => 0.05,
            'worker_drain_rate' => 0.6, // 1/0.6 = 1.67h, below the 2h critical threshold
        ]);

        $this->assertSame(AtlasMaestroReplenisherFeedback::MODE_SELF_HEAL, $r['recommended_originator_mode']);
        $this->assertNotNull($r['worker_drain_horizon_hours']);
        $this->assertLessThanOrEqual(2.0, $r['worker_drain_horizon_hours']);
    }

    public function test_no_worker_drain_rate_yields_null_horizon(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 22,
            'target_depth' => 20,
        ]);

        $this->assertNull($r['worker_drain_horizon_hours']);
    }

    // ── output shape ───────────────────────────────────────────────────────

    public function test_output_includes_all_named_facts(): void
    {
        $r = $this->feedback()->evaluateReplenishmentMode([
            'claimable_depth' => 20,
            'target_depth' => 20,
        ]);

        foreach (['queue_delta', 'task_quality_pressure', 'give_back_pressure', 'worker_drain_horizon_hours', 'recommended_originator_mode', 'mode_reasons'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }
}
