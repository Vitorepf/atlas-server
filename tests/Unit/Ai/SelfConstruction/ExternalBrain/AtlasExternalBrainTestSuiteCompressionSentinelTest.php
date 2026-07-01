<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTestSuiteCompressionSentinel;
use Tests\TestCase;

final class AtlasExternalBrainTestSuiteCompressionSentinelTest extends TestCase
{
    private function sentinel(): AtlasExternalBrainTestSuiteCompressionSentinel
    {
        return new AtlasExternalBrainTestSuiteCompressionSentinel;
    }

    private function equalCounts(): array
    {
        return ['behavior_case_count' => 5, 'negative_case_count' => 3, 'mutation_guard_count' => 2];
    }

    public function test_schema_present(): void
    {
        $r = $this->sentinel()->evaluate(['before' => [], 'after' => []]);
        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::SCHEMA, $r['schema']);
    }

    // ── AC: preserved_test_intent_case — approve only when all 3 categories preserved ──

    public function test_preserved_test_intent_case(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => $this->equalCounts(),
        ]);

        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_APPROVED, $r['verdict']);
        $this->assertSame([], $r['missing_test_intent']);
    }

    public function test_increased_test_intent_is_still_approved(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => ['behavior_case_count' => 6, 'negative_case_count' => 4, 'mutation_guard_count' => 3],
        ]);

        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_APPROVED, $r['verdict']);
    }

    // ── AC: weakened_test_hold_case — any reduced category holds with exact reason ──

    public function test_weakened_behavior_cases_holds_with_exact_reason(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => ['behavior_case_count' => 3, 'negative_case_count' => 3, 'mutation_guard_count' => 2],
        ]);

        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('behavior_case_count:reduced_from_5_to_3', $r['missing_test_intent']);
    }

    public function test_weakened_negative_cases_holds_with_exact_reason(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => ['behavior_case_count' => 5, 'negative_case_count' => 1, 'mutation_guard_count' => 2],
        ]);

        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('negative_case_count:reduced_from_3_to_1', $r['missing_test_intent']);
    }

    public function test_weakened_mutation_guards_holds_with_exact_reason(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => ['behavior_case_count' => 5, 'negative_case_count' => 3, 'mutation_guard_count' => 0],
        ]);

        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_HOLD, $r['verdict']);
        $this->assertContains('mutation_guard_count:reduced_from_2_to_0', $r['missing_test_intent']);
    }

    public function test_multiple_weakened_categories_all_named(): void
    {
        $r = $this->sentinel()->evaluate([
            'before' => $this->equalCounts(),
            'after' => ['behavior_case_count' => 1, 'negative_case_count' => 0, 'mutation_guard_count' => 0],
        ]);

        $this->assertCount(3, $r['missing_test_intent']);
    }

    // ── category_results always has all three categories ─────────────────────

    public function test_category_results_has_all_three_categories(): void
    {
        $r = $this->sentinel()->evaluate(['before' => [], 'after' => []]);

        $categories = array_column($r['category_results'], 'category');
        $this->assertContains('behavior_case_count', $categories);
        $this->assertContains('negative_case_count', $categories);
        $this->assertContains('mutation_guard_count', $categories);
    }

    public function test_missing_before_and_after_defaults_to_zero_and_approves(): void
    {
        $r = $this->sentinel()->evaluate([]);
        $this->assertSame(AtlasExternalBrainTestSuiteCompressionSentinel::VERDICT_APPROVED, $r['verdict']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_evaluate_is_deterministic(): void
    {
        $input = ['before' => $this->equalCounts(), 'after' => ['behavior_case_count' => 4]];

        $this->assertSame(
            json_encode($this->sentinel()->evaluate($input)),
            json_encode($this->sentinel()->evaluate($input)),
        );
    }
}
