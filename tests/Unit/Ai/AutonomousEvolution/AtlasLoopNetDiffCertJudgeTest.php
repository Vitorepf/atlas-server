<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMetricHarness;
use App\Services\Ai\AutonomousEvolution\AtlasLoopNetDiffCertJudge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the NetDiff cert judge for BOTH improvement directions: PASS / REGRESSED / FLAT / ABSTAIN
 * (collector unarmed), the agreement-with-held-out direction semantics (MAXIMIZE: candidate-baseline,
 * MINIMIZE: baseline-candidate), and the no-provider/no-prompt/no-diff-read isolation invariant.
 */
final class AtlasLoopNetDiffCertJudgeTest extends TestCase
{
    private function judge(): AtlasLoopNetDiffCertJudge
    {
        // Explicit construction with min_delta=0.1, tolerance=0.05 so test cases are unambiguous.
        return new AtlasLoopNetDiffCertJudge(minDelta: 0.1, tolerance: 0.05);
    }

    private function collected(string $metricKind, float $baselineValue, float $candidateValue): array
    {
        return [
            'armed' => true,
            'reason' => null,
            'cert_id' => 'cert-1',
            'baseline_side' => ['metric_kind' => $metricKind, 'value' => $baselineValue, 'captured_at' => '2026-06-24T00:00:00Z', 'source_sha' => 'sha-base'],
            'candidate_side' => ['metric_kind' => $metricKind, 'value' => $candidateValue, 'captured_at' => '2026-06-24T00:01:00Z', 'source_sha' => 'sha-cand'],
            'both_sides_present' => true,
        ];
    }

    // ─── MAXIMIZE (improvement-up): candidate > baseline = better ────────────────────────────────────────

    public function test_maximize_clear_improvement_pass(): void
    {
        // signed_delta = 100 - 50 = 50 ⇒ >> min_delta 0.1 ⇒ PASS
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MAXIMIZE, 50.0, 100.0));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_PASS, $v['verdict']);
        $this->assertSame(50.0, $v['signed_delta']);
    }

    public function test_maximize_clear_regression(): void
    {
        // signed_delta = 30 - 80 = -50 ⇒ <= -tolerance ⇒ REGRESSED
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MAXIMIZE, 80.0, 30.0));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_REGRESSED, $v['verdict']);
        $this->assertSame(-50.0, $v['signed_delta']);
    }

    public function test_maximize_within_tolerance_flat(): void
    {
        // signed_delta = 50.02 - 50.0 = 0.02 ⇒ inside (-0.05, 0.1) ⇒ FLAT
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MAXIMIZE, 50.0, 50.02));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_FLAT, $v['verdict']);
    }

    public function test_maximize_collector_unarmed_abstain(): void
    {
        $v = $this->judge()->judge(['armed' => false, 'reason' => 'baseline_equals_candidate']);
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_ABSTAIN, $v['verdict']);
        $this->assertNull($v['signed_delta']);
        $this->assertStringContainsString('collector_unarmed', (string) $v['reason']);
        $this->assertStringContainsString('baseline_equals_candidate', (string) $v['reason']);
    }

    // ─── MINIMIZE (improvement-down): candidate < baseline = better ──────────────────────────────────────

    public function test_minimize_clear_improvement_pass(): void
    {
        // signed_delta = baseline - candidate = 100 - 30 = 70 ⇒ PASS
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MINIMIZE, 100.0, 30.0));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_PASS, $v['verdict']);
        $this->assertSame(70.0, $v['signed_delta']);
    }

    public function test_minimize_clear_regression(): void
    {
        // signed_delta = 20 - 80 = -60 ⇒ REGRESSED
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MINIMIZE, 20.0, 80.0));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_REGRESSED, $v['verdict']);
        $this->assertSame(-60.0, $v['signed_delta']);
    }

    public function test_minimize_within_tolerance_flat(): void
    {
        // signed_delta = 50 - 50.02 = -0.02 ⇒ inside (-0.05, 0.1) ⇒ FLAT
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_MINIMIZE, 50.0, 50.02));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_FLAT, $v['verdict']);
    }

    public function test_minimize_collector_unarmed_abstain(): void
    {
        $v = $this->judge()->judge(['armed' => false, 'reason' => 'baseline_source_sha_missing']);
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_ABSTAIN, $v['verdict']);
    }

    // ─── Pétreo invariants ───────────────────────────────────────────────────────────────────────────────

    public function test_gate_metric_kind_yields_abstain_never_a_numeric_verdict(): void
    {
        $v = $this->judge()->judge($this->collected(AtlasLoopMetricHarness::METRIC_GATE, 1.0, 0.0));
        $this->assertSame(AtlasLoopNetDiffCertJudge::VERDICT_ABSTAIN, $v['verdict']);
        $this->assertStringContainsString('gate', (string) $v['reason']);
    }

    public function test_judge_source_has_no_provider_or_prompt_or_diff_reading_call(): void
    {
        $reflection = new ReflectionClass(AtlasLoopNetDiffCertJudge::class);
        $this->assertTrue($reflection->isFinal());

        $source = (string) file_get_contents($reflection->getFileName());
        foreach (['Http::', '\\Http\\', 'Hermes', 'curl_exec', 'file_get_contents', 'shell_exec', 'exec(', 'proc_open', 'passthru', 'fopen(', 'fsockopen', 'readDiff', '->readPrompt', '->loadPrompt'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "judge must NOT use $banned (provider/prompt/diff coupling)");
        }
    }

    public function test_no_runtime_widening_no_setter_on_min_delta_or_tolerance(): void
    {
        $reflection = new ReflectionClass(AtlasLoopNetDiffCertJudge::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/setMinDelta|setTolerance|widen|relax|disable|bypass|override/i',
                $method->getName(),
                'judge must not expose a runtime widener: '.$method->getName(),
            );
        }
    }
}
