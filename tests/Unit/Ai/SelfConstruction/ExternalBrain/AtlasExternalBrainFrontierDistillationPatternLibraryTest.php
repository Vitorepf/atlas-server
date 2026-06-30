<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierDistillationPatternLibrary;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierDistillationPatternLibraryTest extends TestCase
{
    private AtlasExternalBrainFrontierDistillationPatternLibrary $library;

    protected function setUp(): void
    {
        $this->library = new AtlasExternalBrainFrontierDistillationPatternLibrary;
    }

    private function pattern(array $overrides = []): array
    {
        return array_merge([
            'pattern_id'               => 'p-'.uniqid(),
            'type'                     => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_DECISION_PATTERN,
            'abstract_rule'            => 'When spec lacks runnable proof, reject before enqueue.',
            'success_count'            => 5,
            'give_back_count'          => 0,
            'low_value_count'          => 0,
            'contains_provider_prompt' => false,
            'contains_private_trace'   => false,
        ], $overrides);
    }

    private function input(array ...$patterns): array
    {
        return ['patterns' => $patterns];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->library->distill($this->input($this->pattern()));

        foreach (['schema', 'reusable_patterns', 'retired_patterns', 'provider_safe_summary', 'next_run_injection_rules'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainFrontierDistillationPatternLibrary::SCHEMA, $result['schema']);
    }

    // ── AC2: provider-safe filtering ──────────────────────────────────────────

    public function test_pattern_with_provider_prompt_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['contains_provider_prompt' => true])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame([], $result['retired_patterns']);
    }

    public function test_pattern_with_private_trace_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['contains_private_trace' => true])));

        $this->assertSame([], $result['reusable_patterns']);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['type' => 'raw_prompt'])));

        $this->assertSame([], $result['reusable_patterns']);
    }

    public function test_clean_pattern_is_reusable(): void
    {
        $result = $this->library->distill($this->input($this->pattern()));

        $this->assertCount(1, $result['reusable_patterns']);
        $this->assertSame([], $result['retired_patterns']);
    }

    // ── AC3: retirement by give_back_count ────────────────────────────────────

    public function test_pattern_with_three_or_more_give_backs_is_retired(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['give_back_count' => 3, 'success_count' => 10])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertCount(1, $result['retired_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_GIVE_BACK_COUNT,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    public function test_pattern_with_two_give_backs_is_reusable(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['give_back_count' => 2, 'success_count' => 10])));

        $this->assertCount(1, $result['reusable_patterns']);
    }

    // ── AC3: retirement by give_back rate ─────────────────────────────────────

    public function test_pattern_with_high_give_back_rate_is_retired(): void
    {
        // 3 give_back / 5 total > 50%  — and 5 >= MIN_OUTCOMES_FOR_RATE(4)
        $result = $this->library->distill($this->input($this->pattern([
            'success_count'   => 2,
            'give_back_count' => 3,
            'low_value_count' => 0,
        ])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_GIVE_BACK_COUNT,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    // ── AC3: retirement by zero-success + low-value ───────────────────────────

    public function test_zero_success_with_two_low_value_is_retired(): void
    {
        $result = $this->library->distill($this->input($this->pattern([
            'success_count'   => 0,
            'give_back_count' => 0,
            'low_value_count' => 2,
        ])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_ZERO_VALUE,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    public function test_zero_success_with_one_low_value_is_reusable(): void
    {
        $result = $this->library->distill($this->input($this->pattern([
            'success_count'   => 0,
            'give_back_count' => 0,
            'low_value_count' => 1,
        ])));

        $this->assertCount(1, $result['reusable_patterns']);
    }

    // ── AC3: ranking by success_count ─────────────────────────────────────────

    public function test_reusable_patterns_are_ranked_by_success_desc(): void
    {
        $p1 = $this->pattern(['pattern_id' => 'low',  'success_count' => 2]);
        $p2 = $this->pattern(['pattern_id' => 'high', 'success_count' => 9]);
        $p3 = $this->pattern(['pattern_id' => 'mid',  'success_count' => 5]);

        $result = $this->library->distill($this->input($p1, $p2, $p3));

        $ids = array_column($result['reusable_patterns'], 'pattern_id');
        $this->assertSame(['high', 'mid', 'low'], $ids);
    }

    // ── next_run_injection_rules ──────────────────────────────────────────────

    public function test_injection_rules_come_from_top_patterns(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['pattern_id' => 'top', 'success_count' => 10])));

        $this->assertNotEmpty($result['next_run_injection_rules']);
        $this->assertStringContainsString('success_count=10', $result['next_run_injection_rules'][0]);
    }

    public function test_injection_rules_capped_at_three(): void
    {
        $patterns = array_map(
            fn (int $i) => $this->pattern(['pattern_id' => "p{$i}", 'success_count' => $i]),
            range(1, 6),
        );

        $result = $this->library->distill(['patterns' => $patterns]);

        $this->assertLessThanOrEqual(3, count($result['next_run_injection_rules']));
    }

    // ── provider_safe_summary ─────────────────────────────────────────────────

    public function test_provider_safe_summary_is_non_empty(): void
    {
        $result = $this->library->distill($this->input($this->pattern()));

        $this->assertNotEmpty($result['provider_safe_summary']);
    }

    // ── All three valid types ─────────────────────────────────────────────────

    public function test_all_valid_types_are_accepted(): void
    {
        $result = $this->library->distill($this->input(
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_DECISION_PATTERN]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_FAILURE_CHECK]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_TASK_SHAPING_HEURISTIC]),
        ));

        $this->assertCount(3, $result['reusable_patterns']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_patterns_returns_empty_reusable_and_retired(): void
    {
        $result = $this->library->distill(['patterns' => []]);

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame([], $result['retired_patterns']);
    }
}
