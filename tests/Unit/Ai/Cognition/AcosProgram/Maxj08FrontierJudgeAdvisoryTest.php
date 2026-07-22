<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Compounding\FrontierJudgeAdvisoryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MAXJ-08 — frontier judge advisory tests (§1690).
 *
 *   - Author == judge ⇒ RECUSED (physical author≠judge invariant).
 *   - Missing engine id ⇒ refused with named reason.
 *   - `judge()` never returns `decision`/`status` mutation fields — the
 *     output is strictly a band + rationale + provenance (no veto).
 *   - Ex-post calibration returns per-band denominators with `basis`;
 *     band with n=0 ⇒ `insufficient_sample` (never fabricates lift).
 *   - Death criterion: after N lessons the high-band lift must EXCEED
 *     low-band lift; when high ≤ low ⇒ satisfied=false + recommendation
 *     `remove_judge` (the critério de morte pinned in the schema).
 *   - Rationale is a REF (short handle) — the raw text never enters the
 *     class (provider-safe by construction).
 */
final class Maxj08FrontierJudgeAdvisoryTest extends TestCase
{
    #[Test]
    public function schema_version_is_pinned(): void
    {
        $this->assertSame(
            'atlas.compounding.frontier_judge_advisory.v1',
            FrontierJudgeAdvisoryService::SCHEMA_VERSION,
        );
    }

    #[Test]
    public function same_engine_authored_and_judged_is_refused(): void
    {
        $verdict = (new FrontierJudgeAdvisoryService)->judge([
            'author_engine_id' => 'frontier-x',
            'judge_engine_id' => 'frontier-x',
            'probability' => 0.9,
            'rationale_ref' => 'r1',
        ]);
        $this->assertSame('refused', $verdict['status']);
        $this->assertSame('same_engine_authored_and_judged', $verdict['refusal_reason']);
        $this->assertNull($verdict['advisory_quality_band']);
    }

    #[Test]
    public function missing_engine_id_is_refused(): void
    {
        $verdict = (new FrontierJudgeAdvisoryService)->judge([
            'author_engine_id' => '',
            'judge_engine_id' => 'frontier-x',
            'probability' => 0.9,
        ]);
        $this->assertSame('refused', $verdict['status']);
        $this->assertSame('missing_engine_id', $verdict['refusal_reason']);
    }

    #[Test]
    public function high_probability_produces_high_band_but_never_writes_decision(): void
    {
        $verdict = (new FrontierJudgeAdvisoryService)->judge([
            'author_engine_id' => 'template-distiller',
            'judge_engine_id' => 'frontier-x',
            'probability' => 0.95,
            'rationale_ref' => 'r-42',
        ]);
        $this->assertSame('graded', $verdict['status']);
        $this->assertSame('high', $verdict['advisory_quality_band']);
        $this->assertFalse($verdict['source']['veto']);
        $this->assertFalse($verdict['source']['writes_decision']);
        $this->assertFalse($verdict['source']['writes_status']);
        $this->assertArrayNotHasKey('decision', $verdict);
    }

    #[Test]
    public function calibration_reports_insufficient_sample_when_empty(): void
    {
        $report = (new FrontierJudgeAdvisoryService)->calibration([]);
        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame(0, $report['n_total']);
        foreach (['low', 'sweet', 'high'] as $band) {
            $this->assertSame('insufficient_sample', $report['curve'][$band]['basis']);
            $this->assertNull($report['curve'][$band]['mean_lift']);
        }
        $this->assertFalse($report['death_criterion']['evaluable']);
    }

    #[Test]
    public function calibration_curve_computes_mean_lift_per_band(): void
    {
        $samples = [
            ['advisory_quality_band' => 'low', 'lift' => 0.0],
            ['advisory_quality_band' => 'low', 'lift' => 0.0],
            ['advisory_quality_band' => 'high', 'lift' => 0.4],
            ['advisory_quality_band' => 'high', 'lift' => 0.6],
        ];
        $report = (new FrontierJudgeAdvisoryService)->calibration($samples);
        $this->assertSame(4, $report['n_total']);
        $this->assertEqualsWithDelta(0.0, (float) $report['curve']['low']['mean_lift'], 0.001);
        $this->assertEqualsWithDelta(0.5, (float) $report['curve']['high']['mean_lift'], 0.001);
    }

    #[Test]
    public function death_criterion_satisfied_when_high_beats_low_over_min_n(): void
    {
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $samples[] = ['advisory_quality_band' => 'high', 'lift' => 0.4];
        }
        for ($i = 0; $i < 20; $i++) {
            $samples[] = ['advisory_quality_band' => 'low', 'lift' => 0.0];
        }
        $report = (new FrontierJudgeAdvisoryService)->calibration($samples);
        $this->assertTrue($report['death_criterion']['evaluable']);
        $this->assertTrue($report['death_criterion']['satisfied']);
        $this->assertSame('keep', $report['death_criterion']['recommendation']);
    }

    #[Test]
    public function death_criterion_recommends_remove_when_high_does_not_beat_low(): void
    {
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $samples[] = ['advisory_quality_band' => 'high', 'lift' => 0.0];
        }
        for ($i = 0; $i < 20; $i++) {
            $samples[] = ['advisory_quality_band' => 'low', 'lift' => 0.2];
        }
        $report = (new FrontierJudgeAdvisoryService)->calibration($samples);
        $this->assertTrue($report['death_criterion']['evaluable']);
        $this->assertFalse($report['death_criterion']['satisfied']);
        $this->assertSame('remove_judge', $report['death_criterion']['recommendation']);
    }

    #[Test]
    public function death_criterion_not_evaluable_below_min_n(): void
    {
        $samples = [
            ['advisory_quality_band' => 'high', 'lift' => 0.4],
            ['advisory_quality_band' => 'low', 'lift' => 0.0],
        ];
        $report = (new FrontierJudgeAdvisoryService)->calibration($samples);
        $this->assertFalse($report['death_criterion']['evaluable']);
        $this->assertNull($report['death_criterion']['satisfied']);
    }

    #[Test]
    public function rationale_is_a_ref_not_raw_text(): void
    {
        $verdict = (new FrontierJudgeAdvisoryService)->judge([
            'author_engine_id' => 'template',
            'judge_engine_id' => 'frontier-x',
            'probability' => 0.72,
            'rationale_ref' => 'rat-01',
        ]);
        $this->assertSame('rat-01', $verdict['rationale_ref']);
        // The class NEVER carries a `rationale_text` field — refuse by contract.
        $this->assertArrayNotHasKey('rationale_text', $verdict);
    }
}
