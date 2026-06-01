<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDetectionEngineeringService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Cyber Detection Engineering contract:
 *   - DE2 False Positive Budget ceilings (strict `<`) per severity;
 *   - DE7 lifecycle draft -> staged -> live -> deprecated, with the
 *     `staged -> live` Purple-validation gate, and rejection of skips/backwards;
 *   - DE3 Red-Blue pairing resolution (unpaired = especulativa);
 *   - DE8 behavioral coverage target (>= 30% of live rules);
 *   - the rolled-up rule assessment (TP/TN cases, pairing, inflation, budget).
 *
 * @see docs/engineering-knowledge-base/cyber-security/detection-engineering.md
 */
class AtlasDetectionEngineeringTest extends TestCase
{
    private function service(): AtlasDetectionEngineeringService
    {
        return new AtlasDetectionEngineeringService();
    }

    /** DE2: FP budget is the exact documented ceiling and is strictly exclusive. */
    public function test_false_positive_budget_uses_strict_documented_ceilings(): void
    {
        $service = $this->service();

        // Exact documented table.
        $this->assertSame([
            'critical' => 0.001,
            'high' => 0.005,
            'medium' => 0.02,
            'low' => 0.05,
            'informational' => 0.1,
        ], $service->falsePositiveBudgets());

        // critical < 0.001 -> 0.0009 is within budget, 0.001 itself is NOT (strict).
        $this->assertTrue($service->evaluateFalsePositiveBudget('critical', 0.0009)['within_budget']);
        $atCeiling = $service->evaluateFalsePositiveBudget('critical', 0.001);
        $this->assertFalse($atCeiling['within_budget']);
        $this->assertSame('tune_or_deprecate', $atCeiling['action']);

        // Unknown severity can never be certified within budget.
        $unknown = $service->evaluateFalsePositiveBudget('catastrophic', 0.0);
        $this->assertFalse($unknown['known_severity']);
        $this->assertFalse($unknown['within_budget']);
        $this->assertSame('unknown_severity', $unknown['action']);
    }

    /** DE7: only single forward steps; staged->live needs Purple validation. */
    public function test_lifecycle_transitions_enforce_order_and_purple_gate(): void
    {
        $service = $this->service();

        $this->assertSame(['draft', 'staged', 'live', 'deprecated'], $service->lifecycle());

        // Normal forward step.
        $this->assertTrue($service->evaluateTransition('draft', 'staged')['allowed']);

        // Cannot skip staging ("Skip staging" anti-pattern).
        $skip = $service->evaluateTransition('draft', 'live');
        $this->assertFalse($skip['allowed']);
        $this->assertSame('cannot_skip_lifecycle_step', $skip['reason']);

        // staged -> live without Purple validation is blocked.
        $noPurple = $service->evaluateTransition('staged', 'live', false);
        $this->assertFalse($noPurple['allowed']);
        $this->assertTrue($noPurple['requires_purple_validation']);
        $this->assertSame('purple_validation_required', $noPurple['reason']);

        // staged -> live WITH Purple validation passes.
        $this->assertTrue($service->evaluateTransition('staged', 'live', true)['allowed']);

        // Backward move is rejected.
        $back = $service->evaluateTransition('live', 'staged');
        $this->assertFalse($back['allowed']);
        $this->assertSame('backward_or_noop_transition', $back['reason']);
    }

    /** DE3: canonical pairing resolves; unknown sub-category is unpaired. */
    public function test_red_blue_pairing_resolves_canonical_detection_and_source(): void
    {
        $service = $this->service();

        // Accepts the loose "Auth - Brute force" spelling and resolves canon.
        $paired = $service->pairing('Auth - Brute force');
        $this->assertTrue($paired['paired']);
        $this->assertSame('cyber-det-brute-force', $paired['detection']);
        $this->assertSame('auth_logs', $paired['data_source']);

        // An underscore name survives normalization.
        $this->assertTrue($service->pairing('ad.lsass_read')['paired']);

        // Unknown Red sub-category is not paired -> especulativa.
        $this->assertFalse($service->pairing('webapp.unicorn')['paired']);
    }

    /** DE8: behavioral coverage is measured over LIVE rules against the 30% target. */
    public function test_behavioral_coverage_target_over_live_rules(): void
    {
        $service = $this->service();

        // 1 behavioral + 1 signature live (+ a draft behavioral that must NOT count)
        // => ratio 0.5 over 2 live rules -> meets the 30% target.
        $rules = [
            ['kind' => 'behavioral', 'state' => 'live'],
            ['kind' => 'signature', 'state' => 'live'],
            ['kind' => 'behavioral', 'state' => 'draft'],
        ];
        $cov = $service->behavioralCoverage($rules);
        $this->assertSame(2, $cov['live_total']);
        $this->assertSame(1, $cov['live_behavioral']);
        $this->assertSame(0.5, $cov['ratio']);
        $this->assertSame(0.30, $cov['target_ratio']);
        $this->assertTrue($cov['meets_target']);

        // All-signature live set -> 0% behavioral -> below target.
        $below = $service->behavioralCoverage([
            ['kind' => 'signature', 'state' => 'live'],
            ['kind' => 'signature', 'state' => 'live'],
        ]);
        $this->assertFalse($below['meets_target']);
    }

    /** assessRule rolls up the anti-patterns into one verdict. */
    public function test_assess_rule_flags_missing_tn_cases_pairing_and_over_budget(): void
    {
        $service = $this->service();

        // A clean, paired, testable, in-budget live behavioral rule -> pass.
        $clean = $service->assessRule([
            'id' => 'cyber-det-brute-force',
            'kind' => 'behavioral',
            'state' => 'live',
            'severity' => 'high',
            'justified_severity' => 'high',
            'red_subcategory' => 'auth.brute_force',
            'tp_cases' => 3,
            'tn_cases' => 4,
            'observed_fp_rate' => 0.002,
        ]);
        $this->assertSame('pass', $clean['verdict']);
        $this->assertTrue($clean['promotable_past_draft']);
        $this->assertSame([], $clean['violations']);

        // No TN cases + no Red pairing + live over budget -> multiple violations.
        $bad = $service->assessRule([
            'id' => 'cyber-det-speculative',
            'kind' => 'anomaly',
            'state' => 'live',
            'severity' => 'critical',
            'justified_severity' => 'critical',
            'red_subcategory' => 'webapp.unicorn',
            'tp_cases' => 2,
            'tn_cases' => 0,
            'observed_fp_rate' => 0.5,
        ]);
        $this->assertSame('fail', $bad['verdict']);
        $this->assertFalse($bad['promotable_past_draft']);
        $this->assertContains('missing_tn_cases', $bad['violations']);
        $this->assertContains('missing_red_pairing', $bad['violations']);
        $this->assertContains('live_over_fp_budget', $bad['violations']);

        // DE6 severity inflation: declared critical but justified only low.
        $inflated = $service->assessRule([
            'id' => 'cyber-det-noisy',
            'kind' => 'heuristic',
            'state' => 'staged',
            'severity' => 'critical',
            'justified_severity' => 'low',
            'red_subcategory' => 'webapp.idor',
            'tp_cases' => 1,
            'tn_cases' => 1,
        ]);
        $this->assertContains('severity_inflated', $inflated['violations']);
    }
}
