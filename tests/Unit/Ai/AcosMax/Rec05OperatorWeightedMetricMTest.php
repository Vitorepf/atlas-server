<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Governance\Recursion\OperatorWeightedMetricM;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REC-05 — `M_operator` series alongside neutral M (MEDIDOR).
 *
 * Acceptance clauses from frontier plan §3062-3064:
 *   - two series published (m_operator + m_neutral) with cited weights and
 *     rule source (`rule_ref` present on every component)
 *   - weight without a compiled rule ⇒ invalid (mechanical, not editorial)
 *   - dual-view: divergence between weighted and neutral M is emitted
 */
final class Rec05OperatorWeightedMetricMTest extends TestCase
{
    #[Test]
    public function formula_version_is_pinned(): void
    {
        $this->assertSame('atlas.acos.m_operator.v1', OperatorWeightedMetricM::FORMULA_VERSION);
    }

    #[Test]
    public function complete_inputs_produce_both_series_with_rule_refs_on_every_component(): void
    {
        $m = [
            'coding' => 8.0,
            'review' => 4.0,
            'ops' => 2.0,
        ];
        $weights = [
            'coding' => ['rule_ref' => 'rule://policy/coding-primary', 'usage_frequency' => 0.7],
            'review' => ['rule_ref' => 'rule://policy/review-secondary', 'usage_frequency' => 0.2],
            'ops' => ['rule_ref' => 'rule://policy/ops-tertiary', 'usage_frequency' => 0.1],
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);

        $this->assertSame('measured', $out['status']);
        // m_neutral = (8+4+2)/3 = 4.666...
        $this->assertEqualsWithDelta(14.0 / 3.0, $out['m_neutral'], 1e-4);
        // m_operator = 0.7*8 + 0.2*4 + 0.1*2 = 6.6
        $this->assertEqualsWithDelta(6.6, $out['m_operator'], 1e-4);
        // divergence highlights the personal signal
        $this->assertGreaterThan(0, $out['divergence']);

        foreach (['coding', 'review', 'ops'] as $taskClass) {
            $this->assertArrayHasKey('rule_ref', $out['components'][$taskClass]);
            $this->assertNotEmpty($out['components'][$taskClass]['rule_ref']);
        }
    }

    #[Test]
    public function weight_without_a_compiled_rule_ref_is_refused(): void
    {
        $m = ['coding' => 8.0, 'review' => 4.0];
        $weights = [
            'coding' => ['rule_ref' => 'rule://policy/coding-primary', 'usage_frequency' => 0.7],
            'review' => ['usage_frequency' => 0.3], // NO rule_ref — must be refused
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);

        $this->assertSame('invalid', $out['status']);
        $codes = array_combine(
            array_column($out['errors'], 'field'),
            array_column($out['errors'], 'code'),
        );
        $this->assertSame('missing', $codes['review.rule_ref'] ?? null);
    }

    #[Test]
    public function negative_usage_frequency_is_refused_never_clamped(): void
    {
        $m = ['coding' => 8.0];
        $weights = [
            'coding' => ['rule_ref' => 'rule://policy/coding-primary', 'usage_frequency' => -0.1],
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);
        $this->assertSame('invalid', $out['status']);
    }

    #[Test]
    public function zero_total_weight_returns_insufficient_signal_not_measured(): void
    {
        $m = ['coding' => 5.0];
        $weights = [
            'coding' => ['rule_ref' => 'rule://policy/coding-primary', 'usage_frequency' => 0.0],
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);
        $this->assertSame('insufficient_signal', $out['status']);
    }

    #[Test]
    public function class_present_in_weights_but_missing_from_m_series_is_dropped_silently(): void
    {
        // A class the operator loves that ELEV-02 does not (yet) measure
        // should not tank the metric — the weighted mean is over the
        // OVERLAP with the M series. Divergence remains a signal.
        $m = ['coding' => 6.0];
        $weights = [
            'coding' => ['rule_ref' => 'rule://policy/coding-primary', 'usage_frequency' => 0.5],
            'ops' => ['rule_ref' => 'rule://policy/ops-tertiary', 'usage_frequency' => 0.5],
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);
        $this->assertSame('measured', $out['status']);
        $this->assertSame(['coding'], $out['covered_task_classes']);
    }

    #[Test]
    public function operator_heavy_class_pulls_m_operator_toward_its_own_m(): void
    {
        // The plan's motivating example: high ΔM on a class the operator
        // never uses is worth LESS than average ΔM on the daily flow.
        $m = ['obscure' => 10.0, 'daily' => 3.0];
        $weights = [
            'obscure' => ['rule_ref' => 'rule://policy/obscure', 'usage_frequency' => 0.05],
            'daily' => ['rule_ref' => 'rule://policy/daily', 'usage_frequency' => 0.95],
        ];

        $out = OperatorWeightedMetricM::compute($m, $weights);
        $this->assertSame('measured', $out['status']);
        // m_neutral = (10+3)/2 = 6.5; m_operator = 0.05*10 + 0.95*3 = 0.5 + 2.85 = 3.35
        $this->assertLessThan($out['m_neutral'], $out['m_operator']);
        $this->assertEqualsWithDelta(3.35, $out['m_operator'], 1e-4);
    }
}
