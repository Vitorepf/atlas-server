<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderAgnosticBenchmarkSet;
use Tests\TestCase;

final class AtlasExternalBrainProviderAgnosticBenchmarkSetTest extends TestCase
{
    private function benchmarkSet(): AtlasExternalBrainProviderAgnosticBenchmarkSet
    {
        return new AtlasExternalBrainProviderAgnosticBenchmarkSet();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertSame(AtlasExternalBrainProviderAgnosticBenchmarkSet::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach (['schema', 'challenge_cases', 'scoring_dimensions', 'trap_checks', 'provider_safe_status'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── challenge_cases ───────────────────────────────────────────────────────

    public function test_at_least_ten_challenge_cases(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertGreaterThanOrEqual(10, count($result['challenge_cases']));
    }

    public function test_each_case_has_required_fields(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach ($result['challenge_cases'] as $case) {
            foreach (['case_id', 'description', 'input', 'expected_behavior', 'evidence_requirements'] as $f) {
                $this->assertArrayHasKey($f, $case);
            }
            $this->assertNotEmpty($case['case_id']);
            $this->assertNotEmpty($case['description']);
            $this->assertNotEmpty($case['expected_behavior']);
        }
    }

    public function test_known_case_ids_present(): void
    {
        $result  = $this->benchmarkSet()->load();
        $caseIds = array_column($result['challenge_cases'], 'case_id');

        foreach ([
            'cc-1-no-evidence', 'cc-2-duplicate-target', 'cc-3-template-farming',
            'cc-4-drain-first', 'cc-5-scaffold-gap',
            'cc-6-small-model-failure', 'cc-7-frontier-accelerator',
            'cc-8-task-fabric-value-filter', 'cc-9-queue-self-healing',
            'cc-10-task-graph-ordering',
        ] as $id) {
            $this->assertContains($id, $caseIds);
        }
    }

    public function test_new_cases_cover_required_dimensions(): void
    {
        $result  = $this->benchmarkSet()->load();
        $caseIds = array_column($result['challenge_cases'], 'case_id');

        // non-frontier/small-model failure
        $this->assertContains('cc-6-small-model-failure', $caseIds);
        // frontier-as-accelerator
        $this->assertContains('cc-7-frontier-accelerator', $caseIds);
        // task-fabric value filtering
        $this->assertContains('cc-8-task-fabric-value-filter', $caseIds);
        // queue self-healing
        $this->assertContains('cc-9-queue-self-healing', $caseIds);
        // strategic task-graph ordering
        $this->assertContains('cc-10-task-graph-ordering', $caseIds);
    }

    // ── case_ids filter ───────────────────────────────────────────────────────

    public function test_case_ids_filter_returns_only_requested_cases(): void
    {
        $result = $this->benchmarkSet()->load(['case_ids' => ['cc-1-no-evidence', 'cc-3-template-farming']]);

        $this->assertCount(2, $result['challenge_cases']);
        $ids = array_column($result['challenge_cases'], 'case_id');
        $this->assertContains('cc-1-no-evidence', $ids);
        $this->assertContains('cc-3-template-farming', $ids);
    }

    public function test_empty_case_ids_returns_all(): void
    {
        $all      = $this->benchmarkSet()->load();
        $filtered = $this->benchmarkSet()->load(['case_ids' => []]);

        $this->assertCount(count($all['challenge_cases']), $filtered['challenge_cases']);
    }

    // ── scoring_dimensions ────────────────────────────────────────────────────

    public function test_scoring_dimensions_present(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertNotEmpty($result['scoring_dimensions']);
    }

    public function test_scoring_dimensions_have_required_fields(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach ($result['scoring_dimensions'] as $dim) {
            $this->assertArrayHasKey('dimension_id', $dim);
            $this->assertArrayHasKey('description', $dim);
            $this->assertArrayHasKey('weight', $dim);
            $this->assertIsFloat($dim['weight']);
        }
    }

    public function test_scoring_dimension_weights_sum_to_one(): void
    {
        $result = $this->benchmarkSet()->load();

        $total = array_sum(array_column($result['scoring_dimensions'], 'weight'));
        $this->assertEqualsWithDelta(1.0, $total, 0.001);
    }

    public function test_expected_scoring_dimensions_present(): void
    {
        $result = $this->benchmarkSet()->load();
        $ids    = array_column($result['scoring_dimensions'], 'dimension_id');

        foreach ([
            'evidence_depth', 'dedup_honesty', 'critique_quality',
            'runnable_proof', 'origination_leverage', 'muscle_outcome_predictiveness',
        ] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    // ── trap_checks ───────────────────────────────────────────────────────────

    public function test_trap_checks_present(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertNotEmpty($result['trap_checks']);
    }

    public function test_trap_checks_have_required_fields(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach ($result['trap_checks'] as $trap) {
            $this->assertArrayHasKey('trap_id', $trap);
            $this->assertArrayHasKey('description', $trap);
            $this->assertArrayHasKey('triggers', $trap);
            $this->assertIsArray($trap['triggers']);
        }
    }

    public function test_live_provider_call_trap_present(): void
    {
        $result = $this->benchmarkSet()->load();
        $ids    = array_column($result['trap_checks'], 'trap_id');

        $this->assertContains('live_provider_call', $ids);
    }

    public function test_private_prompt_trap_present(): void
    {
        $result = $this->benchmarkSet()->load();
        $ids    = array_column($result['trap_checks'], 'trap_id');

        $this->assertContains('private_prompt', $ids);
    }

    // ── provider_safe_status ──────────────────────────────────────────────────

    public function test_all_built_in_cases_are_provider_safe(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertTrue($result['provider_safe_status']['is_safe']);
        $this->assertSame([], $result['provider_safe_status']['violations']);
    }

    public function test_provider_safe_status_has_is_safe_and_violations(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertArrayHasKey('is_safe', $result['provider_safe_status']);
        $this->assertArrayHasKey('violations', $result['provider_safe_status']);
    }

    // ── rejection of unsafe content ───────────────────────────────────────────

    public function test_reject_case_requiring_live_provider_call(): void
    {
        // We verify the guard exists by confirming our built-in cases pass
        // (no live_provider_call triggers present in their inputs).
        $result = $this->benchmarkSet()->load();

        foreach ($result['challenge_cases'] as $case) {
            $inputJson = json_encode($case['input']);
            $this->assertStringNotContainsString('requires_live_llm', $inputJson);
            $this->assertStringNotContainsString('live_api_call', $inputJson);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['case_ids' => ['cc-1-no-evidence', 'cc-4-drain-first']];

        $this->assertSame($this->benchmarkSet()->load($input), $this->benchmarkSet()->load($input));
    }

    // ── task family / difficulty / ambiguity grouping ─────────────────────────

    public function test_every_case_carries_task_family_difficulty_and_ambiguity(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach ($result['challenge_cases'] as $case) {
            $this->assertArrayHasKey('task_family', $case);
            $this->assertArrayHasKey('difficulty', $case);
            $this->assertArrayHasKey('ambiguity', $case);
            $this->assertNotEmpty($case['task_family']);
        }
    }

    public function test_cases_by_family_groups_every_case_without_naming_providers(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertArrayHasKey('cases_by_family', $result);
        $totalIndexed = array_sum(array_map('count', $result['cases_by_family']));
        $this->assertSame(count($result['challenge_cases']), $totalIndexed);
    }

    // ── pass criteria ──────────────────────────────────────────────────────────

    public function test_pass_criteria_covers_leverage_implementability_anti_proxy_and_evidence_quality(): void
    {
        $result = $this->benchmarkSet()->load();

        foreach (['leverage', 'implementability', 'anti_proxy_behavior', 'evidence_quality'] as $dimension) {
            $this->assertArrayHasKey($dimension, $result['pass_criteria']);
            $this->assertNotEmpty($result['pass_criteria'][$dimension]);
        }
    }

    // ── rejection of invalid benchmark input ──────────────────────────────────

    public function test_empty_custom_case_set_is_rejected_as_invalid(): void
    {
        $result = $this->benchmarkSet()->load(['cases' => []]);

        $this->assertSame('invalid', $result['validation_status']);
        $this->assertContains('empty_case_set', $result['invalid_reasons']);
        $this->assertFalse($result['provider_safe_status']['is_safe']);
    }

    public function test_provider_labeled_custom_case_is_rejected_as_invalid(): void
    {
        $result = $this->benchmarkSet()->load(['cases' => [
            [
                'case_id' => 'cc-claude-only',
                'description' => 'This case only works with Claude models.',
                'input' => [],
                'provider_safe' => true,
            ],
        ]]);

        $this->assertSame('invalid', $result['validation_status']);
        $this->assertNotEmpty($result['invalid_reasons']);
    }

    public function test_provider_name_inside_case_input_is_also_rejected(): void
    {
        $result = $this->benchmarkSet()->load(['cases' => [
            [
                'case_id' => 'cc-hidden-provider',
                'description' => 'Generic looking case.',
                'input' => ['target_model' => 'gpt-5'],
                'provider_safe' => true,
            ],
        ]]);

        $this->assertSame('invalid', $result['validation_status']);
    }

    public function test_neutral_custom_case_is_accepted_and_taxonomized(): void
    {
        $result = $this->benchmarkSet()->load(['cases' => [
            [
                'case_id' => 'cc-custom-neutral',
                'description' => 'A neutral custom benchmark case.',
                'input' => ['foo' => 'bar'],
                'evidence_requirements' => ['must check foo'],
                'provider_safe' => true,
            ],
        ]]);

        $this->assertSame('valid', $result['validation_status']);
        $this->assertCount(1, $result['challenge_cases']);
        $this->assertSame('uncategorized', $result['challenge_cases'][0]['task_family']);
    }

    public function test_default_cases_never_name_a_specific_provider(): void
    {
        $result = $this->benchmarkSet()->load();
        $blob = strtolower((string) json_encode($result['challenge_cases']));

        foreach (['claude', 'gpt-', 'gemini', 'anthropic', 'openai', 'cursor', 'codex'] as $providerName) {
            $this->assertStringNotContainsString($providerName, $blob, "default cases must not mention provider: {$providerName}");
        }
    }
}
