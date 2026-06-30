<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionFinal95EvidenceSoakVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionFinal95EvidenceSoakVerifierTest extends TestCase
{
    private function verifier(): AtlasSelfConstructionFinal95EvidenceSoakVerifier
    {
        return new AtlasSelfConstructionFinal95EvidenceSoakVerifier;
    }

    private function now(): int { return 1_750_000_000; }

    private function sample(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 's1',
            'timestamp'          => $this->now() - 3600, // 1h ago — fresh
            'queue_pressure'     => 0.3,
            'worker_health'      => 'healthy',
            'merge_success_rate' => 0.95,
            'is_recovery_mode'   => false,
            'evidence_refs'      => ['runtime:telemetry'],
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->verifier()->verify([]);
        $this->assertSame(AtlasSelfConstructionFinal95EvidenceSoakVerifier::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('ready', $r);
        $this->assertArrayHasKey('soak_window', $r);
        $this->assertArrayHasKey('failing_samples', $r);
        $this->assertArrayHasKey('required_repairs', $r);
        $this->assertArrayHasKey('evidence_summary', $r);
    }

    // ── AC3: pass only when every sample healthy ──────────────────────────────

    public function test_ready_when_all_samples_pass_and_count_meets_minimum(): void
    {
        $samples = array_map(
            fn ($i) => $this->sample(['id' => "s$i"]),
            range(1, 5)
        );
        $r = $this->verifier()->verify([
            'soak_samples'      => $samples,
            'min_sample_count'  => 5,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertTrue($r['ready']);
        $this->assertEmpty($r['failing_samples']);
        $this->assertEmpty($r['required_repairs']);
        $this->assertSame(5, $r['evidence_summary']['healthy_count']);
    }

    public function test_not_ready_when_sample_count_below_minimum(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample()],
            'min_sample_count'  => 5,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('insufficient_samples', $r['required_repairs']);
    }

    // ── AC2: failure conditions ───────────────────────────────────────────────

    public function test_recovery_mode_causes_failure(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample(['is_recovery_mode' => true])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('recovery_flagged', $r['failing_samples'][0]['failure_reasons']);
        $this->assertSame(1, $r['evidence_summary']['recovery_mode_count']);
    }

    public function test_empty_evidence_refs_causes_malformed_failure(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample(['evidence_refs' => []])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('malformed_evidence', $r['failing_samples'][0]['failure_reasons']);
    }

    public function test_worker_failed_health_causes_failure(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample(['worker_health' => 'failed'])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('worker_failure', $r['failing_samples'][0]['failure_reasons']);
    }

    public function test_high_queue_pressure_causes_failure(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample(['queue_pressure' => 0.75])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('high_queue_pressure', $r['failing_samples'][0]['failure_reasons']);
    }

    public function test_low_merge_success_rate_causes_failure(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample(['merge_success_rate' => 0.70])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('low_merge_success_rate', $r['failing_samples'][0]['failure_reasons']);
    }

    public function test_stale_evidence_causes_failure(): void
    {
        $staleTs = $this->now() - (25 * 3600); // 25h ago, beyond default 24h freshness
        $r = $this->verifier()->verify([
            'soak_samples'             => [$this->sample(['timestamp' => $staleTs])],
            'min_sample_count'         => 1,
            'freshness_threshold_hours' => 24,
            'current_timestamp'        => $this->now(),
        ]);

        $this->assertFalse($r['ready']);
        $this->assertContains('stale_evidence', $r['failing_samples'][0]['failure_reasons']);
        $this->assertSame(1, $r['evidence_summary']['stale_count']);
    }

    public function test_multiple_failures_accumulated_per_sample(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [$this->sample([
                'is_recovery_mode'   => true,
                'evidence_refs'      => [],
                'queue_pressure'     => 0.9,
            ])],
            'min_sample_count'  => 1,
            'current_timestamp' => $this->now(),
        ]);

        $reasons = $r['failing_samples'][0]['failure_reasons'];
        $this->assertContains('recovery_flagged',  $reasons);
        $this->assertContains('malformed_evidence', $reasons);
        $this->assertContains('high_queue_pressure', $reasons);
    }

    // ── AC3: weakest failure surfaced in required_repairs ─────────────────────

    public function test_required_repairs_are_unique_failure_reasons_across_samples(): void
    {
        $r = $this->verifier()->verify([
            'soak_samples'      => [
                $this->sample(['id' => 'a', 'queue_pressure' => 0.8]),
                $this->sample(['id' => 'b', 'queue_pressure' => 0.9]),
            ],
            'min_sample_count'  => 2,
            'current_timestamp' => $this->now(),
        ]);

        // Both fail with high_queue_pressure but should appear only once in repairs.
        $this->assertSame(['high_queue_pressure'], $r['required_repairs']);
    }

    // ── Soak window ───────────────────────────────────────────────────────────

    public function test_soak_window_includes_sample_count_and_timestamps(): void
    {
        $t1 = $this->now() - 7200;
        $t2 = $this->now() - 3600;
        $r = $this->verifier()->verify([
            'soak_samples'      => [
                $this->sample(['id' => 'a', 'timestamp' => $t1]),
                $this->sample(['id' => 'b', 'timestamp' => $t2]),
            ],
            'min_sample_count'  => 2,
            'soak_window_hours' => 8,
            'current_timestamp' => $this->now(),
        ]);

        $this->assertSame(2, $r['soak_window']['sample_count']);
        $this->assertSame(8, $r['soak_window']['hours']);
        $this->assertSame($t1, $r['soak_window']['start_timestamp']);
        $this->assertSame($t2, $r['soak_window']['end_timestamp']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'soak_samples'      => [
                $this->sample(['id' => 'a']),
                $this->sample(['id' => 'b', 'queue_pressure' => 0.8]),
            ],
            'min_sample_count'  => 2,
            'current_timestamp' => $this->now(),
        ];
        $a = $this->verifier()->verify($facts);
        $b = $this->verifier()->verify($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
