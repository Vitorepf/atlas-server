<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionRiskRadar;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionRiskRadarTest extends TestCase
{
    private function radar(): AtlasExternalBrainCompressionRiskRadar
    {
        return new AtlasExternalBrainCompressionRiskRadar;
    }

    private function healthySignals(array $overrides = []): array
    {
        return array_merge([
            'queue_depth' => 10,
            'give_back_rate' => 0.05,
            'regression_count' => 0,
            'proof_coverage' => 0.95,
            'worker_error_rate' => 0.02,
        ], $overrides);
    }

    // ── AC: proceed_case ────────────────────────────────────────────────────

    public function test_proceed_case_when_all_signals_healthy(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals()]);

        $this->assertSame('proceed', $r['next_action']);
        $this->assertSame([], $r['triggers']);
    }

    // ── AC: give_back_spike_slowdown_case ─────────────────────────────────

    public function test_give_back_spike_slowdown_case(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['give_back_rate' => 0.5])]);

        $this->assertSame('slow_down', $r['next_action']);
        $this->assertNotSame('create_more_compression', $r['next_action']);
        $this->assertContains('give_back_spike', $r['triggers']);
    }

    public function test_worker_error_spike_also_slows_down(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['worker_error_rate' => 0.5])]);

        $this->assertSame('slow_down', $r['next_action']);
        $this->assertContains('worker_error_spike', $r['triggers']);
    }

    // ── AC: regression must stop compression ────────────────────────────────

    public function test_regression_stops_compression(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['regression_count' => 1])]);

        $this->assertSame('stop_compression', $r['next_action']);
        $this->assertNotSame('create_more_compression', $r['next_action']);
        $this->assertContains('regression_detected', $r['triggers']);
    }

    public function test_severely_degraded_proof_coverage_stops_compression(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['proof_coverage' => 0.1])]);

        $this->assertSame('stop_compression', $r['next_action']);
    }

    // ── proof_repair tier ──────────────────────────────────────────────────

    public function test_moderately_degraded_proof_coverage_requires_repair(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['proof_coverage' => 0.5])]);

        $this->assertSame('proof_repair', $r['next_action']);
        $this->assertContains('proof_coverage_degraded', $r['triggers']);
    }

    // ── priority ordering: regression beats give_back spike ─────────────────

    public function test_regression_takes_priority_over_give_back_spike(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals([
            'regression_count' => 2,
            'give_back_rate' => 0.9,
        ])]);

        $this->assertSame('stop_compression', $r['next_action']);
    }

    // ── signals_observed ─────────────────────────────────────────────────────

    public function test_signals_observed_reflects_input(): void
    {
        $r = $this->radar()->radar(['signals' => $this->healthySignals(['queue_depth' => 42])]);

        $this->assertSame(42, $r['signals_observed']['queue_depth']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_radar_is_deterministic(): void
    {
        $facts = ['signals' => $this->healthySignals()];
        $a = $this->radar()->radar($facts);
        $b = $this->radar()->radar($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->radar()->radar([]);
        $this->assertSame(AtlasExternalBrainCompressionRiskRadar::SCHEMA, $r['schema']);
    }
}
