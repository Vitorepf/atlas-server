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
                'The test asserts the scorer returns rejected=false for evidence-grounded specs.',
            ],
            'evidence_fields' => ['tests_or_gates_result', 'implementation_notes'],
            'template_signature' => 'sig_abc123',
            'prior_signatures' => ['sig_old1', 'sig_old2'],
        ];
    }

    /**
     * A proxy-leak example: generic objective with scaffold phrases and no concrete class reference.
     * Should be rejected with at least generic_objective and scaffold_repetition.
     */
    private function proxySpec(): array
    {
        return [
            'objective' => 'Implement the following data handler to process inputs according to the requirements.',
            'acceptance_criteria' => ['The implementation should pass all existing tests.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ];
    }

    /**
     * A repaired spec: the proxySpec rewritten with a concrete class name, runnable gate, and no scaffold phrases.
     * Should not be rejected.
     */
    private function repairedSpec(): array
    {
        return [
            'objective' => 'Implement AtlasDataHandlerService so it reads structured inputs from the task ledger,'
                . ' validates schema contracts, and emits a deterministic DataHandlerResult with no side effects.',
            'acceptance_criteria' => [
                'Runnable: /opt/homebrew/bin/php artisan test --filter=AtlasDataHandlerServiceTest exits 0.',
                'The test asserts AtlasDataHandlerService returns a validated DataHandlerResult for every valid input.',
            ],
            'evidence_fields' => ['tests_or_gates_result', 'implementation_notes'],
        ];
    }

    // ── green path ────────────────────────────────────────────────────────────

    public function test_detect_does_not_mutate_its_input_spec(): void
    {
        $input = ['candidate_spec' => $this->proxySpec()];
        $before = $input;

        $this->svc()->detect($input);

        $this->assertSame($before, $input);
    }

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

    // ── new output fields: severity, proxy_leak_score, killed_reason, repair_prompt_hint ──

    public function test_output_has_new_severity_score_and_repair_fields(): void
    {
        $r = $this->detect($this->goodSpec());

        foreach (['severity', 'proxy_leak_score', 'killed_reason', 'repair_prompt_hint'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing field: {$key}");
        }
    }

    public function test_clean_spec_has_severity_clean_and_score_zero(): void
    {
        $r = $this->detect($this->goodSpec());

        $this->assertSame('clean', $r['severity']);
        $this->assertEqualsWithDelta(0.0, $r['proxy_leak_score'], 0.000001);
    }

    public function test_clean_spec_killed_reason_is_null(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertNull($r['killed_reason']);
    }

    public function test_clean_spec_repair_hint_is_null(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertNull($r['repair_prompt_hint']);
    }

    public function test_rejected_spec_has_non_null_killed_reason_and_repair_hint(): void
    {
        $spec = $this->goodSpec();
        $spec['evidence_fields'] = [];  // triggers missing_evidence

        $r = $this->detect($spec);

        $this->assertNotNull($r['killed_reason']);
        $this->assertIsString($r['repair_prompt_hint']);
        $this->assertNotEmpty($r['repair_prompt_hint']);
    }

    public function test_killed_reason_is_first_leak_signal(): void
    {
        // Template repetition fires first (signal 1) AND missing evidence fires (signal 2).
        $spec = $this->goodSpec();
        $spec['prior_signatures'][] = 'sig_abc123';  // template_repetition
        $spec['evidence_fields']    = [];              // missing_evidence

        $r = $this->detect($spec);

        $this->assertSame($r['leak_reasons'][0], $r['killed_reason']);
        $this->assertSame('template_repetition', $r['killed_reason']);
    }

    public function test_proxy_leak_score_increases_with_more_signals(): void
    {
        $specOne = $this->goodSpec();
        $specOne['evidence_fields'] = [];  // 1 signal: missing_evidence (0.25)

        $specTwo = $this->goodSpec();
        $specTwo['prior_signatures'][] = 'sig_abc123';  // template_repetition (0.30)
        $specTwo['evidence_fields']    = [];              // + missing_evidence (0.25) → 0.55

        $rOne = $this->detect($specOne);
        $rTwo = $this->detect($specTwo);

        $this->assertGreaterThan($rOne['proxy_leak_score'], $rTwo['proxy_leak_score']);
    }

    public function test_severity_low_for_single_low_weight_signal(): void
    {
        // missing_evidence weight = 0.25 → severity 'low' (≤ 0.25)
        $spec = $this->goodSpec();
        $spec['evidence_fields'] = [];

        $r = $this->detect($spec);

        $this->assertSame('low', $r['severity']);
    }

    public function test_severity_medium_for_single_heavier_signal(): void
    {
        // template_repetition weight = 0.30 → severity 'medium' (0.25 < score ≤ 0.50)
        $spec = $this->goodSpec();
        $spec['prior_signatures'][] = 'sig_abc123';

        $r = $this->detect($spec);

        $this->assertSame('medium', $r['severity']);
    }

    public function test_severity_high_for_combined_heavy_signals(): void
    {
        // template_repetition (0.30) + missing_evidence (0.25) = 0.55 > 0.50 → 'high'
        $spec = $this->goodSpec();
        $spec['prior_signatures'][] = 'sig_abc123';
        $spec['evidence_fields']    = [];

        $r = $this->detect($spec);

        $this->assertSame('high', $r['severity']);
    }

    // ── generic_objective signal ──────────────────────────────────────────────

    public function test_objective_with_no_fqcn_or_path_triggers_generic_objective(): void
    {
        // No PascalCase multi-segment class name, no path ref anywhere in spec.
        $r = $this->detect([
            'objective' => 'Process input data and produce structured output with no side effects for downstream use.',
            'acceptance_criteria' => ['All unit tests must pass successfully.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertContains('generic_objective', $r['leak_reasons']);
    }

    public function test_fqcn_in_acceptance_prevents_generic_objective(): void
    {
        // "AtlasScorerTest" is a PascalCase 2+ segment class → hasConcreteTarget=true
        $r = $this->detect([
            'objective' => 'Process input data and produce structured output with no side effects.',
            'acceptance_criteria' => ['Run AtlasScorerTest and verify it exits 0.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertNotContains('generic_objective', $r['leak_reasons']);
    }

    public function test_fqcn_in_objective_prevents_generic_objective(): void
    {
        $r = $this->detect([
            'objective' => 'Implement AtlasCapabilityScorer to compute delta scores across five capability dimensions and return a structured result with no side effects.',
            'acceptance_criteria' => ['All unit tests must pass.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertNotContains('generic_objective', $r['leak_reasons']);
    }

    public function test_path_reference_in_acceptance_prevents_generic_objective(): void
    {
        $r = $this->detect([
            'objective' => 'Process input data and produce structured output with no side effects.',
            'acceptance_criteria' => ['Run tests/Unit/ScorerTest.php and verify it exits 0.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertNotContains('generic_objective', $r['leak_reasons']);
    }

    public function test_empty_objective_does_not_trigger_generic_objective(): void
    {
        $r = $this->svc()->detect(['candidate_spec' => []]);
        $this->assertNotContains('generic_objective', $r['leak_reasons']);
    }

    // ── scaffold_repetition signal ────────────────────────────────────────────

    public function test_scaffold_phrase_in_objective_triggers_scaffold_repetition(): void
    {
        $spec = $this->goodSpec();
        $spec['objective'] = 'Implement the following requirements for the task handler service.';

        $r = $this->detect($spec);

        $this->assertContains('scaffold_repetition', $r['leak_reasons']);
    }

    public function test_scaffold_phrase_in_acceptance_triggers_scaffold_repetition(): void
    {
        $spec = $this->goodSpec();
        $spec['acceptance_criteria'] = [
            'The implementation should work following the existing pattern for all edge cases.',
            'Runnable: php artisan test AtlasScorerTest exits 0.',
        ];

        $r = $this->detect($spec);

        $this->assertContains('scaffold_repetition', $r['leak_reasons']);
    }

    public function test_spec_without_scaffold_phrases_does_not_trigger_scaffold_repetition(): void
    {
        $r = $this->detect($this->goodSpec());
        $this->assertNotContains('scaffold_repetition', $r['leak_reasons']);
    }

    // ── excessive_similarity signal ───────────────────────────────────────────

    public function test_high_similarity_to_recent_spec_triggers_excessive_similarity(): void
    {
        $objective = 'Implement a pure deterministic scorer that evaluates capability growth.';
        $spec = $this->goodSpec();
        $spec['objective']             = $objective;
        $spec['recent_accepted_specs'] = [$objective];  // identical → Jaccard = 1.0

        $r = $this->detect($spec);

        $this->assertContains('excessive_similarity', $r['leak_reasons']);
    }

    public function test_dissimilar_recent_spec_does_not_trigger_excessive_similarity(): void
    {
        $spec = $this->goodSpec();
        $spec['recent_accepted_specs'] = [
            'Build a distributed cache invalidation layer with TTL support and Redis backend.',
        ];

        $r = $this->detect($spec);

        $this->assertNotContains('excessive_similarity', $r['leak_reasons']);
    }

    public function test_no_recent_specs_never_triggers_excessive_similarity(): void
    {
        $spec = $this->goodSpec();
        unset($spec['recent_accepted_specs']);

        $r = $this->detect($spec);

        $this->assertNotContains('excessive_similarity', $r['leak_reasons']);
    }

    // ── genuine / proxy-leak / repaired examples ──────────────────────────────

    public function test_genuine_spec_not_rejected(): void
    {
        $r = $this->detect($this->goodSpec());

        $this->assertFalse($r['rejected']);
        $this->assertSame('clean', $r['severity']);
    }

    public function test_proxy_leak_spec_is_rejected_with_multiple_signals(): void
    {
        $r = $this->detect($this->proxySpec());

        $this->assertTrue($r['rejected']);
        $this->assertContains('generic_objective',  $r['leak_reasons']);
        $this->assertContains('scaffold_repetition', $r['leak_reasons']);
        $this->assertGreaterThan(0.0, $r['proxy_leak_score']);
    }

    public function test_repaired_spec_not_rejected(): void
    {
        // The repairedSpec was produced by fixing the proxySpec:
        //   - Added FQCN (AtlasDataHandlerService) → no generic_objective
        //   - Removed scaffold phrases → no scaffold_repetition
        //   - Added runnable acceptance gate → no missing_evidence
        $r = $this->detect($this->repairedSpec());

        $this->assertFalse($r['rejected']);
        $this->assertSame('clean', $r['severity']);
        $this->assertNull($r['killed_reason']);
    }

    // ── weak_acceptance signal ─────────────────────────────────────────────────

    public function test_bare_exits_zero_acceptance_with_no_assertion_triggers_weak_acceptance(): void
    {
        $r = $this->detect([
            'objective' => 'Implement AtlasCapabilityScorer to compute delta scores across five capability dimensions and return a structured result with no side effects.',
            'acceptance_criteria' => ['Runnable: php artisan test AtlasCapabilityScorerTest exits 0.'],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertContains('weak_acceptance', $r['leak_reasons']);
        $this->assertTrue($r['rejected']);
    }

    public function test_runnable_acceptance_with_behavior_assertion_and_value_proof_is_not_weak(): void
    {
        $r = $this->detect([
            'objective' => 'Implement AtlasCapabilityScorer to compute delta scores across five capability dimensions and return a structured result with no side effects.',
            'acceptance_criteria' => [
                'Runnable: php artisan test AtlasCapabilityScorerTest exits 0.',
                'The test asserts the scorer emits a positive value_delta for capability growth.',
            ],
            'evidence_fields' => ['tests_or_gates_result'],
        ]);

        $this->assertNotContains('weak_acceptance', $r['leak_reasons']);
    }

    public function test_template_repetition_and_excessive_similarity_remain_active_alongside_weak_acceptance(): void
    {
        $spec = $this->goodSpec();
        $spec['acceptance_criteria'] = ['Runnable: php artisan test AtlasScorerTest exits 0.'];
        $spec['prior_signatures'][] = 'sig_abc123';
        $spec['recent_accepted_specs'] = [$spec['objective']];

        $r = $this->detect($spec);

        $this->assertContains('template_repetition', $r['leak_reasons']);
        $this->assertContains('excessive_similarity', $r['leak_reasons']);
        $this->assertContains('weak_acceptance', $r['leak_reasons']);
    }
}
