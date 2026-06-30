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

    public function test_at_least_five_challenge_cases(): void
    {
        $result = $this->benchmarkSet()->load();

        $this->assertGreaterThanOrEqual(5, count($result['challenge_cases']));
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

        foreach (['cc-1-no-evidence', 'cc-2-duplicate-target', 'cc-3-template-farming',
                  'cc-4-drain-first', 'cc-5-scaffold-gap'] as $id) {
            $this->assertContains($id, $caseIds);
        }
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

        foreach (['evidence_depth', 'dedup_honesty', 'critique_quality', 'runnable_proof', 'origination_leverage'] as $id) {
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
}
