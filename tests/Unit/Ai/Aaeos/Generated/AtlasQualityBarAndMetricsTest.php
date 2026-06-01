<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasQualityBarAndMetricsService;
use Tests\TestCase;

final class AtlasQualityBarAndMetricsTest extends TestCase
{
    private AtlasQualityBarAndMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasQualityBarAndMetricsService();
    }

    /**
     * Doc "Score Bands" table: each documented range maps to its band meaning.
     * 2 -> ad hoc, 5 -> documented/weak, 7 -> governed/testable, 8 -> agent-executable.
     */
    public function testScoreBandsMapToDocumentedRanges(): void
    {
        $this->assertSame('ad_hoc', $this->service->scoreBand(2)['band_key']);
        $this->assertSame('documented_weak', $this->service->scoreBand(5)['band_key']);
        $this->assertSame('governed_testable', $this->service->scoreBand(7)['band_key']);
        $this->assertSame('agent_executable', $this->service->scoreBand(8)['band_key']);
    }

    /**
     * Doc hard rule: "No score above 9 is valid without repeated autonomous runs
     * and low drift." A 9.5 claim with no proofs must be invalid, clamped to the
     * effective ceiling of 9, and list both missing proofs.
     */
    public function testScoreAboveNineWithoutProofsIsInvalidAndClampedToNine(): void
    {
        $result = $this->service->scoreBand(9.5);

        $this->assertTrue($result['above_nine_claimed']);
        $this->assertFalse($result['above_nine_valid']);
        $this->assertTrue($result['clamped']);
        $this->assertSame(9.0, $result['effective_score']);
        $this->assertSame(['repeated autonomous runs', 'low drift'], $result['missing_proofs']);
    }

    /**
     * The same above-9 claim becomes valid (and is NOT clamped) only when BOTH
     * documented proofs are supplied. A single proof must still leave it invalid.
     */
    public function testScoreAboveNineValidOnlyWithBothProofs(): void
    {
        $onlyRuns = $this->service->scoreBand(10, ['repeated_autonomous_runs' => true]);
        $this->assertFalse($onlyRuns['above_nine_valid']);
        $this->assertTrue($onlyRuns['clamped']);
        $this->assertSame(9.0, $onlyRuns['effective_score']);

        $both = $this->service->scoreBand(10, [
            'repeated_autonomous_runs' => true,
            'low_drift' => true,
        ]);
        $this->assertTrue($both['above_nine_valid']);
        $this->assertFalse($both['clamped']);
        $this->assertSame(10.0, $both['effective_score']);
        $this->assertSame('self_improving', $both['band_key']);
        $this->assertSame([], $both['missing_proofs']);
    }

    /**
     * Doc "Definition Of Done": a block is done only when ALL 8 conditions hold.
     * Empty state denies done and lists all 8 missing; the full set flips it true.
     */
    public function testDefinitionOfDoneRequiresAllEightConditions(): void
    {
        $empty = $this->service->definitionOfDone([]);
        $this->assertFalse($empty['done']);
        $this->assertSame(8, $empty['total_conditions']);
        $this->assertCount(8, $empty['missing']);
        $this->assertSame(0, $empty['satisfied_count']);

        $full = [
            'docs_ap_spec_exist' => true,
            'code_matches_spec' => true,
            'focused_tests_pass' => true,
            'architecture_doc_gates_pass' => true,
            'evidence_append_only' => true,
            'drift_checked' => true,
            'maturity_delta_stated' => true,
            'residual_risk_explicit' => true,
        ];
        $this->assertTrue($this->service->definitionOfDone($full)['done']);

        // Dropping a single documented condition must re-deny done.
        $missingDrift = $full;
        unset($missingDrift['drift_checked']);
        $denied = $this->service->definitionOfDone($missingDrift);
        $this->assertFalse($denied['done']);
        $this->assertSame(['drift is checked'], $denied['missing']);
    }

    /**
     * Doc "Metrics" table + "Quality Gates" block: the canonical contract exposes
     * exactly 9 metric dimensions and 10 ordered gates, with docs-health first.
     */
    public function testContractExposesNineMetricsAndTenOrderedGates(): void
    {
        $contract = $this->service->contract();

        $this->assertSame(9, $contract['metric_dimension_count']);
        $this->assertSame(10, $contract['quality_gate_count']);
        $this->assertSame('docs-health', $contract['quality_gates'][0]);
        $this->assertSame('diff check', $contract['quality_gates'][9]);
        $this->assertSame(9, $contract['strict_ceiling']);
        $this->assertCount(8, $contract['definition_of_done']);
    }
}
