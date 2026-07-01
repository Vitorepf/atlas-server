<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostMergeCompressionAuditor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPostMergeCompressionAuditorTest extends TestCase
{
    private function auditor(): AtlasExternalBrainPostMergeCompressionAuditor
    {
        return new AtlasExternalBrainPostMergeCompressionAuditor;
    }

    private function promise(array $overrides = []): array
    {
        return array_merge([
            'fitness_score' => 0.8,
            'proof_coverage' => 0.9,
            'capability_score' => 1.0,
            'line_reduction' => 100,
        ], $overrides);
    }

    // ── AC: promise_met_case ────────────────────────────────────────────────

    public function test_promise_met_case_when_actual_meets_or_exceeds_every_promised_metric(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(),
        ]);

        $this->assertSame('promise_met', $r['status']);
        $this->assertSame([], $r['failed_promises']);
    }

    public function test_promise_met_case_when_actual_exceeds_promised(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['fitness_score' => 0.95, 'line_reduction' => 150]),
        ]);

        $this->assertSame('promise_met', $r['status']);
    }

    // ── AC: promise_missed_repair_case ──────────────────────────────────────

    public function test_promise_missed_repair_case_names_exact_failed_promise(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['fitness_score' => 0.5]),
        ]);

        $this->assertSame('repair_required', $r['status']);
        $this->assertSame(['fitness_score'], $r['failed_promises']);
    }

    public function test_missed_line_reduction_promise_is_named(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['line_reduction' => 20]),
        ]);

        $this->assertSame('repair_required', $r['status']);
        $this->assertContains('line_reduction', $r['failed_promises']);
    }

    public function test_missed_proof_coverage_promise_is_named(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['proof_coverage' => 0.4]),
        ]);

        $this->assertContains('proof_coverage', $r['failed_promises']);
    }

    public function test_missed_capability_promise_is_named(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['capability_score' => 0.6]),
        ]);

        $this->assertContains('capability_score', $r['failed_promises']);
    }

    public function test_multiple_missed_promises_all_named(): void
    {
        $r = $this->auditor()->audit([
            'promised' => $this->promise(),
            'actual' => $this->promise(['fitness_score' => 0.1, 'proof_coverage' => 0.1]),
        ]);

        $this->assertCount(2, $r['failed_promises']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_audit_is_deterministic(): void
    {
        $facts = ['promised' => $this->promise(), 'actual' => $this->promise(['fitness_score' => 0.5])];
        $a = $this->auditor()->audit($facts);
        $b = $this->auditor()->audit($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->auditor()->audit([]);
        $this->assertSame(AtlasExternalBrainPostMergeCompressionAuditor::SCHEMA, $r['schema']);
    }
}
