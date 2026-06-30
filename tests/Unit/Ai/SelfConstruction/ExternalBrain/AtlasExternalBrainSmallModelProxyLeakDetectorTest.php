<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSmallModelProxyLeakDetector;
use Tests\TestCase;

final class AtlasExternalBrainSmallModelProxyLeakDetectorTest extends TestCase
{
    private function svc(): AtlasExternalBrainSmallModelProxyLeakDetector
    {
        return new AtlasExternalBrainSmallModelProxyLeakDetector;
    }

    private function detect(array $spec): array
    {
        return $this->svc()->detect(['candidate_spec' => $spec]);
    }

    /** A well-formed, evidence-grounded spec that should not be rejected. */
    private function goodSpec(): array
    {
        return [
            'objective' => 'Implement a pure deterministic scorer that evaluates capability growth by reading'
                . ' execution evidence from the ledger, computing delta scores across five dimensions,'
                . ' and returning a structured calibration report with no side effects.',
            'acceptance_criteria' => [
                'Runnable: /opt/homebrew/bin/php artisan test AtlasScorerTest exits 0.',
                'The scorer returns rejected=false for evidence-grounded specs.',
            ],
            'evidence_fields' => ['tests_or_gates_result', 'implementation_notes'],
            'template_signature' => 'sig_abc123',
            'prior_signatures' => ['sig_old1', 'sig_old2'],
        ];
    }

    // ── green path ────────────────────────────────────────────────────────────

    public function test_good_spec_is_not_rejected(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertFalse($r['rejected']);
        $this->assertSame([], $r['leak_reasons']);
    }

    public function test_good_spec_has_runnable_acceptance_in_quality_signal(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertTrue($r['quality_signal']['has_runnable_acceptance']);
    }

    // ── template repetition ───────────────────────────────────────────────────

    public function test_template_signature_in_prior_list_triggers_rejection(): void
    {
        $spec = $this->goodSpec();
        $spec['prior_signatures'][] = 'sig_abc123';

        $r = $this->detect($spec);
        $this->assertTrue($r['rejected']);
        $this->assertContains('template_repetition', $r['leak_reasons']);
    }

    public function test_empty_signature_never_triggers_template_repetition(): void
    {
        $spec = $this->goodSpec();
        $spec['template_signature'] = '';
        $spec['prior_signatures'] = ['', 'other'];

        $r = $this->detect($spec);
        $this->assertNotContains('template_repetition', $r['leak_reasons']);
    }

    // ── missing evidence ──────────────────────────────────────────────────────

    public function test_empty_evidence_fields_triggers_missing_evidence(): void
    {
        $spec = $this->goodSpec();
        $spec['evidence_fields'] = [];

        $r = $this->detect($spec);
        $this->assertContains('missing_evidence', $r['leak_reasons']);
    }

    public function test_acceptance_criteria_without_runnable_marker_triggers_missing_evidence(): void
    {
        $spec = $this->goodSpec();
        $spec['acceptance_criteria'] = ['The class should exist and compile correctly.'];

        $r = $this->detect($spec);
        $this->assertContains('missing_evidence', $r['leak_reasons']);
    }

    public function test_runnable_marker_in_acceptance_satisfies_evidence_check(): void
    {
        $spec = $this->goodSpec();
        // evidence_fields is non-empty and acceptance has 'runnable'
        $r = $this->detect($spec);
        $this->assertNotContains('missing_evidence', $r['leak_reasons']);
    }

    // ── cosmetic wrapper ──────────────────────────────────────────────────────

    public function test_short_objective_with_cosmetic_keyword_triggers_cosmetic_wrapper(): void
    {
        $spec = $this->goodSpec();
        // < 20 words AND contains 'wrapper'
        $spec['objective'] = 'Simple wrapper around existing scorer class.';

        $r = $this->detect($spec);
        $this->assertContains('cosmetic_wrapper', $r['leak_reasons']);
    }

    public function test_long_objective_with_cosmetic_keyword_not_cosmetic(): void
    {
        $spec = $this->goodSpec();
        // >= 20 words, so not cosmetic even if keyword present
        $spec['objective'] = 'Implement a thin wrapper around the existing external scorer that preserves all'
            . ' input contracts, applies a retry policy for transient failures, emits structured'
            . ' telemetry events, and returns the scorer output unchanged to the caller.';

        $r = $this->detect($spec);
        $this->assertNotContains('cosmetic_wrapper', $r['leak_reasons']);
    }

    public function test_short_objective_without_cosmetic_keyword_not_cosmetic(): void
    {
        $spec = $this->goodSpec();
        $spec['objective'] = 'Compute the score and return it.';  // short but no cosmetic keyword

        $r = $this->detect($spec);
        $this->assertNotContains('cosmetic_wrapper', $r['leak_reasons']);
    }

    // ── benchmark overfit ─────────────────────────────────────────────────────

    public function test_all_metric_only_criteria_with_no_runnable_triggers_benchmark_overfit(): void
    {
        $spec = $this->goodSpec();
        $spec['acceptance_criteria'] = [
            'The score metric must exceed 0.85.',
            'The success rate percentage must be above 70%.',
        ];

        $r = $this->detect($spec);
        $this->assertContains('benchmark_overfit', $r['leak_reasons']);
    }

    public function test_mixed_criteria_with_runnable_does_not_trigger_benchmark_overfit(): void
    {
        $spec = $this->goodSpec();
        // has runnable marker → no benchmark_overfit
        $r = $this->detect($spec);
        $this->assertNotContains('benchmark_overfit', $r['leak_reasons']);
    }

    // ── quality_signal ────────────────────────────────────────────────────────

    public function test_quality_signal_reports_word_count(): void
    {
        $spec = $this->goodSpec();
        $r = $this->detect($spec);
        $this->assertGreaterThan(AtlasExternalBrainSmallModelProxyLeakDetector::MIN_OBJECTIVE_WORDS,
            $r['quality_signal']['objective_word_count']);
    }

    public function test_quality_signal_reports_evidence_field_count(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertSame(2, $r['quality_signal']['evidence_field_count']);
    }

    public function test_quality_signal_template_collision_true_on_repetition(): void
    {
        $spec = $this->goodSpec();
        $spec['prior_signatures'][] = 'sig_abc123';
        $r = $this->detect($spec);
        $this->assertTrue($r['quality_signal']['template_collision']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->detect([]);
        $this->assertSame(AtlasExternalBrainSmallModelProxyLeakDetector::SCHEMA, $r['schema_version']);
    }
}
