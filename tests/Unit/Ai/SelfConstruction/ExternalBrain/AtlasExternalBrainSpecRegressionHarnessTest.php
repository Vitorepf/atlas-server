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
}
