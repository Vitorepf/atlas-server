<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroDrainContinuitySloCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroDrainContinuitySloCompilerTest extends TestCase
{
    private function compiler(): AtlasMaestroDrainContinuitySloCompiler
    {
        return new AtlasMaestroDrainContinuitySloCompiler;
    }

    public function test_empty_facts_yield_healthy_status(): void
    {
        $r = $this->compiler()->compile([]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_HEALTHY, $r['status']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::SCHEMA, $r['schema_version']);
    }

    public function test_queue_with_zero_servable_now_is_starved_and_critical(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 50,
            'servable_now' => 0,
            'active_workers' => 4,
        ]);

        $this->assertSame('starved', $r['verdicts']['servable_supply']);
        $this->assertContains('queue_starved_no_servable_work', $r['blockers']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_CRITICAL, $r['status']);
    }

    public function test_servable_now_below_active_workers_is_at_risk_supply(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 10,
            'servable_now' => 2,
            'active_workers' => 5,
        ]);

        $this->assertSame('at_risk', $r['verdicts']['servable_supply']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_AT_RISK, $r['status']);
    }

    public function test_servable_now_covers_active_workers_is_healthy_supply(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 10,
            'servable_now' => 5,
            'active_workers' => 5,
        ]);

        $this->assertSame('healthy', $r['verdicts']['servable_supply']);
    }

    public function test_drain_eta_computed_from_average_throughput(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 100,
            'servable_now' => 100,
            'throughput_samples' => [10.0, 10.0],
        ]);

        $this->assertSame(10.0, $r['verdicts']['drain_eta_hours']);
        $this->assertSame('healthy', $r['verdicts']['drain_eta']);
    }

    public function test_drain_eta_at_risk_when_exceeds_threshold_hours(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 1000,
            'servable_now' => 1000,
            'throughput_samples' => [10.0],
        ]);

        $this->assertSame(100.0, $r['verdicts']['drain_eta_hours']);
        $this->assertSame('at_risk', $r['verdicts']['drain_eta']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_AT_RISK, $r['status']);
    }

    public function test_zero_throughput_with_pending_queue_is_unknown_and_blocks(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 50,
            'servable_now' => 50,
            'throughput_samples' => [],
        ]);

        $this->assertSame('unknown_zero_throughput', $r['verdicts']['drain_eta']);
        $this->assertNull($r['verdicts']['drain_eta_hours']);
        $this->assertContains('zero_throughput_with_pending_queue', $r['blockers']);
    }

    public function test_zero_throughput_with_empty_queue_is_healthy(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 0,
            'throughput_samples' => [],
        ]);

        $this->assertSame('healthy', $r['verdicts']['drain_eta']);
        $this->assertSame(0.0, $r['verdicts']['drain_eta_hours']);
    }

    public function test_high_give_back_rate_flags_amplification_risk(): void
    {
        $r = $this->compiler()->compile(['give_back_rate' => 0.5]);

        $this->assertSame('amplifying_risk', $r['verdicts']['give_back_amplification']);
        $this->assertContains('give_back_rate_amplifying_queue', $r['blockers']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_AT_RISK, $r['status']);
    }

    public function test_low_give_back_rate_is_healthy(): void
    {
        $r = $this->compiler()->compile(['give_back_rate' => 0.1]);

        $this->assertSame('healthy', $r['verdicts']['give_back_amplification']);
    }

    public function test_claim_latency_breach_is_flagged(): void
    {
        $r = $this->compiler()->compile(['claim_latency_seconds' => 45.0]);

        $this->assertSame('breached', $r['verdicts']['claim_latency']);
        $this->assertContains('claim_latency_slo_breached', $r['blockers']);
        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_AT_RISK, $r['status']);
    }

    public function test_claim_latency_within_slo_is_healthy(): void
    {
        $r = $this->compiler()->compile(['claim_latency_seconds' => 5.0]);

        $this->assertSame('within_slo', $r['verdicts']['claim_latency']);
    }

    public function test_starved_status_wins_over_other_at_risk_signals(): void
    {
        $r = $this->compiler()->compile([
            'queue_depth' => 50,
            'servable_now' => 0,
            'active_workers' => 4,
            'give_back_rate' => 0.9,
            'claim_latency_seconds' => 99.0,
        ]);

        $this->assertSame(AtlasMaestroDrainContinuitySloCompiler::STATUS_CRITICAL, $r['status']);
    }

    public function test_identical_input_yields_identical_output(): void
    {
        $facts = [
            'queue_depth' => 20,
            'servable_now' => 10,
            'active_workers' => 3,
            'throughput_samples' => [5.0, 7.0],
            'give_back_rate' => 0.2,
            'claim_latency_seconds' => 12.0,
        ];

        $this->assertSame($this->compiler()->compile($facts), $this->compiler()->compile($facts));
    }

    public function test_no_quality_score_field_in_output(): void
    {
        $r = $this->compiler()->compile(['queue_depth' => 5]);

        $json = (string) json_encode($r);
        foreach (['"score":', '"rank":', '"rating":', '"quality":'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
