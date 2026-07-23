<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainRuntimeShadowComparator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainRuntimeShadowComparatorTest extends TestCase
{
    private function comparator(): AtlasExternalBrainRuntimeShadowComparator
    {
        return new AtlasExternalBrainRuntimeShadowComparator;
    }

    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'output' => ['status' => 'ok', 'count' => 3],
            'errors' => [],
            'evidence_hash' => 'hash-abc-123',
        ], $overrides);
    }

    public function test_schema_present(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
        ]]);

        $this->assertSame(AtlasExternalBrainRuntimeShadowComparator::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
        ]]);

        foreach (['schema', 'approved', 'case_results', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    // ── matching_shadow_case ─────────────────────────────────────────────────

    public function test_matching_shadow_observation_is_approved(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
        ]]);

        $this->assertTrue($result['approved']);
        $this->assertTrue($result['case_results'][0]['approved']);
        $this->assertSame([], $result['case_results'][0]['mismatch_paths']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_errors_match_regardless_of_order(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            [
                'case_id' => 'c1',
                'expected' => $this->receipt(['errors' => ['err_a', 'err_b']]),
                'shadow' => $this->receipt(['errors' => ['err_b', 'err_a']]),
            ],
        ]]);

        $this->assertTrue($result['approved']);
    }

    public function test_multiple_matching_cases_are_all_approved(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
            ['case_id' => 'c2', 'expected' => $this->receipt(['evidence_hash' => 'hash-2']), 'shadow' => $this->receipt(['evidence_hash' => 'hash-2'])],
        ]]);

        $this->assertTrue($result['approved']);
        $this->assertCount(2, $result['case_results']);
    }

    // ── divergent_shadow_fail_case ───────────────────────────────────────────

    public function test_divergent_output_fails_closed_with_output_mismatch_path(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            [
                'case_id' => 'c1',
                'expected' => $this->receipt(['output' => ['status' => 'ok', 'count' => 3]]),
                'shadow' => $this->receipt(['output' => ['status' => 'ok', 'count' => 4]]),
            ],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertContains('output', $result['case_results'][0]['mismatch_paths']);
        $this->assertContains('c1:output', $result['reasons']);
    }

    public function test_divergent_errors_fails_closed_with_errors_mismatch_path(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            [
                'case_id' => 'c1',
                'expected' => $this->receipt(['errors' => []]),
                'shadow' => $this->receipt(['errors' => ['unexpected_runtime_error']]),
            ],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertContains('errors', $result['case_results'][0]['mismatch_paths']);
    }

    public function test_divergent_evidence_hash_fails_closed_with_evidence_hash_mismatch_path(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            [
                'case_id' => 'c1',
                'expected' => $this->receipt(['evidence_hash' => 'hash-expected']),
                'shadow' => $this->receipt(['evidence_hash' => 'hash-drifted']),
            ],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertContains('evidence_hash', $result['case_results'][0]['mismatch_paths']);
    }

    public function test_missing_shadow_observation_fails_closed(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt()],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertContains('shadow_observation_missing', $result['case_results'][0]['mismatch_paths']);
    }

    public function test_missing_expected_evidence_hash_never_matches_by_accident(): void
    {
        // Both sides empty-string would be a false match on '===' alone — must be treated as a mismatch.
        $result = $this->comparator()->compare(['cases' => [
            [
                'case_id' => 'c1',
                'expected' => ['output' => 'x', 'errors' => [], 'evidence_hash' => ''],
                'shadow' => ['output' => 'x', 'errors' => [], 'evidence_hash' => ''],
            ],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertContains('evidence_hash', $result['case_results'][0]['mismatch_paths']);
    }

    public function test_one_divergent_case_among_matches_fails_the_whole_comparison(): void
    {
        $result = $this->comparator()->compare(['cases' => [
            ['case_id' => 'good', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
            ['case_id' => 'bad', 'expected' => $this->receipt(['output' => 'a']), 'shadow' => $this->receipt(['output' => 'b'])],
        ]]);

        $this->assertFalse($result['approved']);
        $this->assertTrue($result['case_results'][0]['approved']);
        $this->assertFalse($result['case_results'][1]['approved']);
    }

    public function test_no_cases_declared_fails_closed(): void
    {
        $result = $this->comparator()->compare(['cases' => []]);

        $this->assertFalse($result['approved']);
        $this->assertContains('no_cases_declared', $result['reasons']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $comparator = $this->comparator();
        $facts = ['cases' => [
            ['case_id' => 'c1', 'expected' => $this->receipt(), 'shadow' => $this->receipt()],
        ]];

        $this->assertSame($comparator->compare($facts), $comparator->compare($facts));
    }
}
