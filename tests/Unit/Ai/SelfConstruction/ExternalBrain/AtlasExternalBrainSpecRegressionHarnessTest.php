<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecRegressionHarness;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSpecRegressionHarnessTest extends TestCase
{
    private AtlasExternalBrainSpecRegressionHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AtlasExternalBrainSpecRegressionHarness;
    }

    private function spec(array $overrides = []): array
    {
        return array_merge([
            'task_id'              => 'cand-001',
            'objective'            => 'Implement a unique novel service with distinct behavior',
            'allowed_files'        => ['app/Services/Foo.php'],
            'acceptance_criteria'  => ['must pass tests'],
            'implementation_files' => [],
            'test_files'           => [],
            'behavior_evidence'    => [],
        ], $overrides);
    }

    private function example(string $label, string $objective): array
    {
        return ['label' => $label, 'objective' => $objective];
    }

    private function input(array $candidates, array $historical = []): array
    {
        return ['candidate_specs' => $candidates, 'historical_examples' => $historical];
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        foreach (['schema', 'verdict', 'matched_regressions', 'evidence'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::SCHEMA, $result['schema']);
    }

    // ── AC1: pass when no historical matches ──────────────────────────────────

    public function test_clean_candidate_passes(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
        $this->assertSame([], $result['matched_regressions']);
    }

    // ── AC1: poison class → fail ──────────────────────────────────────────────

    public function test_candidate_matching_poison_example_produces_fail(): void
    {
        $candidate = $this->spec(['objective' => 'implement queue saturation poison detection service']);
        $poisonEx  = $this->example('poison', 'queue saturation poison detection service implement');

        $result = $this->harness->replay($this->input([$candidate], [$poisonEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_POISON, $result['matched_regressions'][0]['class']);
        $this->assertSame('poison', $result['matched_regressions'][0]['matched_example_label']);
    }

    public function test_candidate_matching_give_back_example_produces_fail(): void
    {
        $candidate = $this->spec(['objective' => 'implement queue saturation give back detection service']);
        $gbEx      = $this->example('give_back', 'queue saturation give back detection service implement');

        $result = $this->harness->replay($this->input([$candidate], [$gbEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $this->assertSame('give_back', $result['matched_regressions'][0]['matched_example_label']);
    }

    // ── AC1: wrapper_farm class → warning ────────────────────────────────────

    public function test_candidate_matching_shallow_wrapper_produces_warning(): void
    {
        $candidate  = $this->spec(['objective' => 'wrap existing service with thin delegation layer proxy']);
        $wrapperEx  = $this->example('shallow_wrapper', 'thin delegation layer proxy wrap existing service');

        $result = $this->harness->replay($this->input([$candidate], [$wrapperEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_WRAPPER_FARM, $result['matched_regressions'][0]['class']);
    }

    // ── AC1: duplicate class → warning ────────────────────────────────────────

    public function test_candidate_matching_duplicate_example_produces_warning(): void
    {
        $candidate = $this->spec(['objective' => 'duplicate task objective same content again']);
        $dupEx     = $this->example('duplicate', 'duplicate task objective same content again');

        $result = $this->harness->replay($this->input([$candidate], [$dupEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE, $result['matched_regressions'][0]['class']);
    }

    // ── AC1: underspecified_scope → warning ───────────────────────────────────

    public function test_candidate_with_no_impl_no_tests_no_criteria_is_underspecified(): void
    {
        $candidate = $this->spec([
            'acceptance_criteria'  => [],
            'implementation_files' => [],
            'test_files'           => [],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_UNDERSPECIFIED, $result['matched_regressions'][0]['class']);
    }

    public function test_candidate_with_acceptance_criteria_is_not_underspecified(): void
    {
        $candidate = $this->spec([
            'acceptance_criteria'  => ['must prove green tests'],
            'implementation_files' => [],
            'test_files'           => [],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
    }

    // ── AC1: fail beats warning ───────────────────────────────────────────────

    public function test_fail_overrides_warning(): void
    {
        $poisonCandidate = $this->spec([
            'task_id'   => 'c1',
            'objective' => 'implement queue saturation poison detection service',
        ]);
        $wrapperCandidate = $this->spec([
            'task_id'   => 'c2',
            'objective' => 'wrap existing service with thin delegation layer proxy',
        ]);

        $examples = [
            $this->example('poison',         'queue saturation poison detection service implement'),
            $this->example('shallow_wrapper', 'thin delegation layer proxy wrap existing service'),
        ];

        $result = $this->harness->replay($this->input([$poisonCandidate, $wrapperCandidate], $examples));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
    }

    // ── AC2: known-good macro spec is never blocked ───────────────────────────

    public function test_known_good_macro_spec_is_not_blocked_even_when_similar_to_poison(): void
    {
        $macro = $this->spec([
            'objective'            => 'implement queue saturation poison detection service',
            'implementation_files' => ['app/Services/Foo.php'],
            'test_files'           => ['tests/Unit/FooTest.php'],
            'behavior_evidence'    => ['tests pass green: phpunit FooTest exits 0'],
        ]);

        $poisonEx = $this->example('poison', 'queue saturation poison detection service implement');

        $result = $this->harness->replay($this->input([$macro], [$poisonEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
        $this->assertSame([], $result['matched_regressions']);
    }

    public function test_known_good_macro_spec_requires_all_three_signals(): void
    {
        // Missing behavior_evidence → NOT known-good → regression check applies
        $candidate = $this->spec([
            'objective'            => 'implement queue saturation poison detection service',
            'implementation_files' => ['app/Services/Foo.php'],
            'test_files'           => ['tests/Unit/FooTest.php'],
            'behavior_evidence'    => [],  // missing
        ]);

        $poisonEx = $this->example('poison', 'queue saturation poison detection service implement');

        $result = $this->harness->replay($this->input([$candidate], [$poisonEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
    }

    // ── Cross-candidate duplicate detection ───────────────────────────────────

    public function test_two_very_similar_candidates_triggers_duplicate_warning(): void
    {
        $c1 = $this->spec(['task_id' => 'a', 'objective' => 'implement entropy restoration planner service component']);
        $c2 = $this->spec(['task_id' => 'b', 'objective' => 'implement entropy restoration planner service component']);

        $result = $this->harness->replay($this->input([$c1, $c2]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
        $classes = array_column($result['matched_regressions'], 'class');
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE, $classes);
    }

    // ── Empty inputs ──────────────────────────────────────────────────────────

    public function test_empty_candidates_returns_pass(): void
    {
        $result = $this->harness->replay($this->input([]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
        $this->assertSame([], $result['matched_regressions']);
    }

    // ── Evidence is always populated ──────────────────────────────────────────

    public function test_evidence_is_always_non_empty(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertNotEmpty($result['evidence']);
    }

    // ── Frozen poison classes (regression freeze) ─────────────────────────────

    public function test_test_only_packet_fails_and_reports_gate(): void
    {
        $candidate = $this->spec([
            'allowed_files' => ['tests/Unit/FooTest.php', 'tests/Unit/BarTest.php'],
            'implementation_files' => [],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $match = $result['matched_regressions'][0];
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_TEST_ONLY_PACKET, $match['class']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::GATE_BY_CLASS[AtlasExternalBrainSpecRegressionHarness::CLASS_TEST_ONLY_PACKET], $match['gate']);
    }

    public function test_forbidden_implementation_target_fails_and_reports_gate(): void
    {
        $candidate = $this->spec(['allowed_files' => ['app/Services/Petreo/Sacred.php']]);

        $result = $this->harness->replay([
            'candidate_specs' => [$candidate],
            'forbidden_targets' => ['app/Services/Petreo/Sacred.php'],
        ]);

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $match = $result['matched_regressions'][0];
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_FORBIDDEN_TARGET, $match['class']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::GATE_BY_CLASS[AtlasExternalBrainSpecRegressionHarness::CLASS_FORBIDDEN_TARGET], $match['gate']);
    }

    public function test_contradictory_acceptance_fails_and_reports_gate(): void
    {
        $candidate = $this->spec([
            'acceptance_criteria' => [
                'the endpoint must always return cached results',
                'the endpoint must never return cached results',
            ],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $match = $result['matched_regressions'][0];
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_CONTRADICTORY_ACCEPTANCE, $match['class']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::GATE_BY_CLASS[AtlasExternalBrainSpecRegressionHarness::CLASS_CONTRADICTORY_ACCEPTANCE], $match['gate']);
    }

    public function test_duplicate_target_warns_and_reports_gate(): void
    {
        $c1 = $this->spec(['task_id' => 'a', 'objective' => 'first unrelated objective text alpha', 'allowed_files' => ['app/Services/Shared.php']]);
        $c2 = $this->spec(['task_id' => 'b', 'objective' => 'second unrelated objective text beta', 'allowed_files' => ['app/Services/Shared.php']]);

        $result = $this->harness->replay($this->input([$c1, $c2]));

        $classes = array_column($result['matched_regressions'], 'class');
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE_TARGET, $classes);
        $match = $result['matched_regressions'][array_search(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE_TARGET, $classes, true)];
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::GATE_BY_CLASS[AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE_TARGET], $match['gate']);
    }

    public function test_template_farm_spec_warns_and_reports_gate(): void
    {
        $candidate = $this->spec(['objective' => 'generate boilerplate template scaffold for new module quickly']);
        $templateEx = $this->example('template_farm', 'generate boilerplate template scaffold for new module quickly');

        $result = $this->harness->replay($this->input([$candidate], [$templateEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
        $match = $result['matched_regressions'][0];
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::CLASS_TEMPLATE_FARM, $match['class']);
        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::GATE_BY_CLASS[AtlasExternalBrainSpecRegressionHarness::CLASS_TEMPLATE_FARM], $match['gate']);
    }

    public function test_good_high_value_macro_spec_is_never_blocked_by_any_frozen_poison_class(): void
    {
        $macro = $this->spec([
            'objective'            => 'generate boilerplate template scaffold for new module quickly',
            'allowed_files'        => ['tests/Unit/FooTest.php'],
            'acceptance_criteria'  => ['must always pass', 'must never fail'],
            'implementation_files' => ['app/Services/Foo.php'],
            'test_files'           => ['tests/Unit/FooTest.php'],
            'behavior_evidence'    => ['tests pass green: phpunit FooTest exits 0'],
        ]);

        $result = $this->harness->replay([
            'candidate_specs' => [$macro],
            'forbidden_targets' => ['tests/Unit/FooTest.php'],
        ]);

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
        $this->assertSame([], $result['matched_regressions']);
    }

    public function test_candidate_combining_test_only_duplicate_target_and_contradictory_acceptance_reports_all_regressions(): void
    {
        $candidate = $this->spec([
            'task_id'             => 'multi-regress-001',
            'allowed_files'       => ['tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['the output must be valid json', 'the output must not be valid json'],
        ]);
        $sibling = $this->spec([
            'task_id'       => 'sibling-001',
            'objective'     => 'Implement a totally different independent capability elsewhere',
            'allowed_files' => ['tests/Unit/FooTest.php'],
        ]);

        $result = $this->harness->replay($this->input([$candidate, $sibling]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);

        $classes = array_column(
            array_filter($result['matched_regressions'], static fn (array $m): bool => $m['spec_id'] === 'multi-regress-001'),
            'class',
        );
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_TEST_ONLY_PACKET, $classes);
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE_TARGET, $classes);
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_CONTRADICTORY_ACCEPTANCE, $classes);

        foreach ($result['matched_regressions'] as $match) {
            $this->assertNotSame('', $match['evidence']);
        }
    }

    // ── AC1: historical template-farm BATCH still fails replay ──────────────────

    public function test_batch_of_template_farm_candidates_fails_replay(): void
    {
        $templateEx = $this->example('template_farm', 'generate boilerplate template scaffold for new module quickly');

        $c1 = $this->spec(['task_id' => 'tf-1', 'allowed_files' => ['app/Services/Foo.php'], 'objective' => 'generate boilerplate template scaffold quickly alpha bravo charlie']);
        $c2 = $this->spec(['task_id' => 'tf-2', 'allowed_files' => ['app/Services/Bar.php'], 'objective' => 'generate boilerplate template scaffold module delta echo foxtrot']);

        $result = $this->harness->replay($this->input([$c1, $c2], [$templateEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $classes = array_column($result['matched_regressions'], 'class');
        $this->assertCount(2, array_filter($classes, static fn (string $c): bool => $c === AtlasExternalBrainSpecRegressionHarness::CLASS_TEMPLATE_FARM));
    }

    public function test_single_template_farm_candidate_still_only_warns(): void
    {
        // Regression guard: a single template_farm match must stay a warning, not escalate.
        $templateEx = $this->example('template_farm', 'generate boilerplate template scaffold for new module quickly');
        $c1 = $this->spec(['objective' => 'generate boilerplate template scaffold for new module quickly']);

        $result = $this->harness->replay($this->input([$c1], [$templateEx]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_WARNING, $result['verdict']);
    }

    // ── AC2: historical contradictory packet still fails replay ─────────────────

    public function test_historical_contradictory_packet_still_fails_replay(): void
    {
        $candidate = $this->spec([
            'objective' => 'implement caching layer for endpoint responses',
            'acceptance_criteria' => [
                'the cache must always be invalidated on write',
                'the cache must never be invalidated on write',
            ],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_FAIL, $result['verdict']);
        $classes = array_column($result['matched_regressions'], 'class');
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_CONTRADICTORY_ACCEPTANCE, $classes);
    }

    // ── AC3: good worker-ready spec passes and reports covered_regressions ──────

    public function test_good_worker_ready_spec_passes_and_reports_covered_regressions(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertSame(AtlasExternalBrainSpecRegressionHarness::VERDICT_PASS, $result['verdict']);
        $this->assertArrayHasKey('covered_regressions', $result);
        $this->assertNotEmpty($result['covered_regressions']);
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_POISON, $result['covered_regressions']);
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_TEMPLATE_FARM, $result['covered_regressions']);
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_CONTRADICTORY_ACCEPTANCE, $result['covered_regressions']);
    }

    public function test_covered_regressions_present_even_when_replay_fails(): void
    {
        $candidate = $this->spec(['objective' => 'implement queue saturation poison detection service']);
        $poisonEx  = $this->example('poison', 'queue saturation poison detection service implement');

        $result = $this->harness->replay($this->input([$candidate], [$poisonEx]));

        $this->assertArrayHasKey('covered_regressions', $result);
        $this->assertNotEmpty($result['covered_regressions']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: replayed_fixtures, failed_fixtures, repaired_spec_hints
    // ═══════════════════════════════════════════════════════════════════════

    public function test_output_includes_ac4_keys(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertArrayHasKey('replayed_fixtures', $result);
        $this->assertArrayHasKey('failed_fixtures', $result);
        $this->assertArrayHasKey('repaired_spec_hints', $result);
    }

    public function test_replayed_fixtures_reports_count_of_historical_examples(): void
    {
        $result = $this->harness->replay($this->input(
            [$this->spec()],
            [$this->example('poison', 'bad pattern A'), $this->example('duplicate', 'bad pattern B')],
        ));

        $this->assertSame(2, $result['replayed_fixtures']);
    }

    public function test_replayed_fixtures_zero_when_no_historical_examples(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertSame(0, $result['replayed_fixtures']);
    }

    public function test_failed_fixtures_lists_labels_that_caused_a_fail(): void
    {
        $candidate = $this->spec(['objective' => 'implement queue saturation poison detection service']);
        $poisonEx  = $this->example('poison', 'queue saturation poison detection service implement');

        $result = $this->harness->replay($this->input([$candidate], [$poisonEx]));

        $this->assertContains('poison', $result['failed_fixtures']);
    }

    public function test_failed_fixtures_empty_when_no_failures(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertSame([], $result['failed_fixtures']);
    }

    public function test_failed_fixtures_does_not_include_warnings(): void
    {
        $candidate = $this->spec(['objective' => 'wrap existing service with thin delegation layer proxy']);
        $wrapperEx = $this->example('shallow_wrapper', 'thin delegation layer proxy wrap existing service');

        $result = $this->harness->replay($this->input([$candidate], [$wrapperEx]));

        // shallow_wrapper → warning, not fail
        $this->assertSame([], $result['failed_fixtures'],
            'failed_fixtures must not include warning-level matches');
    }

    public function test_repaired_spec_hints_includes_all_matched_regression_classes(): void
    {
        // Two candidates with very similar objectives and identical target → duplicate + duplicate_target
        $c1 = $this->spec([
            'task_id' => 'cand-001',
            'objective' => 'implement entropy restoration planner service component',
            'allowed_files' => ['app/Services/Shared.php'],
        ]);
        $c2 = $this->spec([
            'task_id' => 'cand-002',
            'objective' => 'implement entropy restoration planner service component',
            'allowed_files' => ['app/Services/Shared.php'],
        ]);

        $result = $this->harness->replay($this->input([$c1, $c2]));

        $this->assertNotEmpty($result['repaired_spec_hints']);
        $hintClasses = array_column($result['repaired_spec_hints'], 'class');
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE, $hintClasses,
            'duplicate cross-candidate match must produce a repair hint');
        $this->assertContains(AtlasExternalBrainSpecRegressionHarness::CLASS_DUPLICATE_TARGET, $hintClasses,
            'duplicate_target match must produce a repair hint');
    }

    public function test_repaired_spec_hints_empty_when_no_regressions(): void
    {
        $result = $this->harness->replay($this->input([$this->spec()]));

        $this->assertSame([], $result['repaired_spec_hints']);
    }

    public function test_repaired_spec_hints_contains_human_readable_text(): void
    {
        $candidate = $this->spec([
            'acceptance_criteria' => [
                'the system must always return json',
                'the system must never return json',
            ],
        ]);

        $result = $this->harness->replay($this->input([$candidate]));

        $this->assertNotEmpty($result['repaired_spec_hints']);
        $hint = $result['repaired_spec_hints'][0];
        $this->assertArrayHasKey('class', $hint);
        $this->assertArrayHasKey('hint', $hint);
        $this->assertNotEmpty($hint['hint']);
        $this->assertStringContainsString('conflicting', strtolower($hint['hint']));
    }
}
