<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAdmissionThresholdCalibrator;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricAdmissionThresholdCalibratorTest extends TestCase
{
    private AtlasTaskFabricAdmissionThresholdCalibrator $cal;

    protected function setUp(): void
    {
        $this->cal = new AtlasTaskFabricAdmissionThresholdCalibrator;
    }

    private function good(
        float $value = 0.8, float $risk = 0.2, float $dup = 0.1, float $templ = 0.1,
        string $outcome = 'success',
    ): array {
        return [
            'value_score'        => $value,
            'risk_score'         => $risk,
            'duplicate_score'    => $dup,
            'template_similarity' => $templ,
            'outcome'            => $outcome,
        ];
    }

    private function bad(
        float $value = 0.3, float $risk = 0.8, float $dup = 0.9, float $templ = 0.9,
        string $outcome = 'poison',
    ): array {
        return [
            'value_score'        => $value,
            'risk_score'         => $risk,
            'duplicate_score'    => $dup,
            'template_similarity' => $templ,
            'outcome'            => $outcome,
        ];
    }

    private function calibrate(array $rows): array
    {
        return $this->cal->calibrate(['replay_rows' => $rows]);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->calibrate([$this->good()]);

        $this->assertSame(AtlasTaskFabricAdmissionThresholdCalibrator::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('threshold_set', $r);
        $this->assertArrayHasKey('expected_confusion_matrix', $r);
    }

    public function test_threshold_set_has_all_dimensions(): void
    {
        $r = $this->calibrate([$this->good()]);

        foreach (['value_score', 'risk_score', 'duplicate_score', 'template_similarity'] as $k) {
            $this->assertArrayHasKey($k, $r['threshold_set'], "Missing dimension: {$k}");
            $this->assertIsFloat($r['threshold_set'][$k]);
        }
    }

    public function test_confusion_matrix_has_all_fields(): void
    {
        $r = $this->calibrate([$this->good()]);

        foreach (['true_positive', 'false_positive', 'false_negative', 'true_negative'] as $k) {
            $this->assertArrayHasKey($k, $r['expected_confusion_matrix'], "Missing: {$k}");
            $this->assertIsInt($r['expected_confusion_matrix'][$k]);
        }
    }

    // ── Threshold derivation ─────────────────────────────────────────────────

    public function test_value_threshold_is_min_of_good_values(): void
    {
        $r = $this->calibrate([$this->good(0.7), $this->good(0.9), $this->good(0.8)]);

        $this->assertEqualsWithDelta(0.7, $r['threshold_set']['value_score'], 0.0001);
    }

    public function test_risk_threshold_is_max_of_good_risk(): void
    {
        $r = $this->calibrate([$this->good(risk: 0.2), $this->good(risk: 0.3), $this->good(risk: 0.25)]);

        $this->assertEqualsWithDelta(0.3, $r['threshold_set']['risk_score'], 0.0001);
    }

    public function test_duplicate_threshold_is_max_of_good_dup(): void
    {
        $r = $this->calibrate([$this->good(dup: 0.1), $this->good(dup: 0.15)]);

        $this->assertEqualsWithDelta(0.15, $r['threshold_set']['duplicate_score'], 0.0001);
    }

    public function test_template_threshold_is_max_of_good_template(): void
    {
        $r = $this->calibrate([$this->good(templ: 0.1), $this->good(templ: 0.2)]);

        $this->assertEqualsWithDelta(0.2, $r['threshold_set']['template_similarity'], 0.0001);
    }

    // ── Default thresholds when no good rows ─────────────────────────────────

    public function test_empty_rows_returns_default_thresholds(): void
    {
        $r = $this->cal->calibrate([]);

        $ts = $r['threshold_set'];
        $this->assertEqualsWithDelta(0.0, $ts['value_score'],         0.0001);
        $this->assertEqualsWithDelta(1.0, $ts['risk_score'],          0.0001);
        $this->assertEqualsWithDelta(1.0, $ts['duplicate_score'],     0.0001);
        $this->assertEqualsWithDelta(1.0, $ts['template_similarity'], 0.0001);
    }

    public function test_only_bad_rows_uses_default_thresholds(): void
    {
        $r = $this->calibrate([$this->bad(), $this->bad()]);

        $ts = $r['threshold_set'];
        $this->assertEqualsWithDelta(0.0, $ts['value_score'], 0.0001);
    }

    // ── Catching bad rows ─────────────────────────────────────────────────────

    public function test_high_risk_bad_rows_are_caught(): void
    {
        $r = $this->calibrate([$this->good(risk: 0.2), $this->bad(risk: 0.8)]);

        // threshold_risk = 0.2; bad row risk=0.8 > 0.2 → rejected (TN=1)
        $this->assertSame(1, $r['expected_confusion_matrix']['true_negative']);
        $this->assertSame(0, $r['expected_confusion_matrix']['false_positive']);
    }

    public function test_high_duplicate_score_bad_rows_are_caught(): void
    {
        $r = $this->calibrate([$this->good(dup: 0.1), $this->bad(dup: 0.9, outcome: 'duplicate')]);

        // threshold_dup = 0.1; bad row dup=0.9 > 0.1 → rejected
        $this->assertSame(1, $r['expected_confusion_matrix']['true_negative']);
        $this->assertSame(0, $r['expected_confusion_matrix']['false_positive']);
    }

    public function test_high_template_similarity_bad_rows_are_caught(): void
    {
        $r = $this->calibrate([$this->good(templ: 0.1), $this->bad(templ: 0.9, outcome: 'template_farm')]);

        $this->assertSame(1, $r['expected_confusion_matrix']['true_negative']);
        $this->assertSame(0, $r['expected_confusion_matrix']['false_positive']);
    }

    public function test_give_back_outcome_is_treated_as_bad(): void
    {
        $r = $this->calibrate([
            $this->good(risk: 0.2),
            ['value_score' => 0.3, 'risk_score' => 0.9, 'duplicate_score' => 0.5, 'template_similarity' => 0.5, 'outcome' => 'give_back'],
        ]);

        $this->assertSame(1, $r['expected_confusion_matrix']['true_negative']);
    }

    // ── Perfect separation ────────────────────────────────────────────────────

    public function test_perfectly_separable_rows_yield_zero_fp_and_fn(): void
    {
        $rows = [
            $this->good(0.8, 0.2, 0.1, 0.1, 'success'),
            $this->good(0.9, 0.3, 0.15, 0.12, 'success'),
            $this->good(0.85, 0.25, 0.1, 0.1, 'high_impact'),
            $this->bad(0.2, 0.8, 0.9, 0.9, 'poison'),
            $this->bad(0.1, 0.9, 0.8, 0.85, 'give_back'),
        ];

        $r  = $this->calibrate($rows);
        $mx = $r['expected_confusion_matrix'];

        $this->assertSame(0, $mx['false_positive']);
        $this->assertSame(0, $mx['false_negative']);
        $this->assertSame(3, $mx['true_positive']);
        $this->assertSame(2, $mx['true_negative']);
    }

    // ── high_impact is treated as good ───────────────────────────────────────

    public function test_high_impact_outcome_is_admitted(): void
    {
        $r = $this->calibrate([$this->good(outcome: 'high_impact')]);

        $this->assertSame(1, $r['expected_confusion_matrix']['true_positive']);
    }

    public function test_green_commit_outcome_is_admitted(): void
    {
        $r = $this->calibrate([$this->good(outcome: 'green_commit')]);

        $this->assertSame(1, $r['expected_confusion_matrix']['true_positive']);
    }

    // ── Confusion matrix totals ───────────────────────────────────────────────

    public function test_confusion_matrix_sums_to_total_row_count(): void
    {
        $rows = [$this->good(), $this->good(), $this->bad(), $this->bad(), $this->bad()];
        $r    = $this->calibrate($rows);
        $mx   = $r['expected_confusion_matrix'];

        $this->assertSame(5, $mx['true_positive'] + $mx['false_positive'] + $mx['false_negative'] + $mx['true_negative']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $rows = [$this->good(), $this->good(0.75), $this->bad(), $this->bad(risk: 0.6)];

        $this->assertSame(json_encode($this->calibrate($rows)), json_encode($this->calibrate($rows)));
    }
}
