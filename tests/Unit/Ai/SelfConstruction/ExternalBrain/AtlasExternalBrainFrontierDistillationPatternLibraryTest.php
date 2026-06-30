<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierDistillationPatternLibrary;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierDistillationPatternLibraryTest extends TestCase
{
    private AtlasExternalBrainFrontierDistillationPatternLibrary $library;

    private int $seq = 0;

    protected function setUp(): void
    {
        $this->library = new AtlasExternalBrainFrontierDistillationPatternLibrary;
        $this->seq     = 0;
    }

    private function pattern(array $overrides = []): array
    {
        return array_merge([
            'pattern_id'                          => 'p-'.(++$this->seq),
            'type'                                => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_DECISION_PATTERN,
            'abstract_rule'                       => 'When spec lacks runnable proof, reject before enqueue.',
            'success_count'                       => 5,
            'give_back_count'                     => 0,
            'low_value_count'                     => 0,
            'contains_provider_prompt'            => false,
            'contains_private_trace'              => false,
            'contains_provider_session_id'        => false,
            'contains_unredacted_prompt_fragment' => false,
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

        foreach (['schema', 'reusable_patterns', 'retired_patterns', 'rejected_patterns', 'provider_safe_summary', 'next_run_injection_rules', 'pattern_quality_score'] as $k) {
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
        $this->assertCount(1, $result['rejected_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_PROMPT,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    public function test_pattern_with_private_trace_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['contains_private_trace' => true])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PRIVATE_TRACE,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    public function test_pattern_with_provider_session_id_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['contains_provider_session_id' => true])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_SESSION_ID,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    public function test_pattern_with_unredacted_prompt_fragment_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['contains_unredacted_prompt_fragment' => true])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_UNREDACTED_PROMPT_FRAGMENT,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    public function test_invalid_type_is_rejected(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['type' => 'raw_prompt'])));

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_INVALID_TYPE,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    public function test_clean_pattern_is_reusable(): void
    {
        $result = $this->library->distill($this->input($this->pattern()));

        $this->assertCount(1, $result['reusable_patterns']);
        $this->assertSame([], $result['retired_patterns']);
        $this->assertSame([], $result['rejected_patterns']);
    }

    public function test_provider_prompt_beats_private_trace_in_rejection_order(): void
    {
        $result = $this->library->distill($this->input($this->pattern([
            'contains_provider_prompt' => true,
            'contains_private_trace'   => true,
        ])));

        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_PROMPT,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
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
        $p1 = $this->pattern(['success_count' => 2]);
        $p2 = $this->pattern(['success_count' => 9]);
        $p3 = $this->pattern(['success_count' => 5]);

        $result = $this->library->distill($this->input($p1, $p2, $p3));

        $scores = array_column($result['reusable_patterns'], 'success_count');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    // ── next_run_injection_rules ──────────────────────────────────────────────

    public function test_injection_rules_come_from_top_patterns(): void
    {
        $result = $this->library->distill($this->input($this->pattern(['success_count' => 10, 'abstract_rule' => 'top rule'])));

        $this->assertNotEmpty($result['next_run_injection_rules']);
        $this->assertStringContainsString('success_count=10', $result['next_run_injection_rules'][0]);
    }

    public function test_injection_rules_capped_at_three(): void
    {
        $patterns = [];
        for ($i = 1; $i <= 6; $i++) {
            $patterns[] = $this->pattern(['success_count' => $i]);
        }

        $result = $this->library->distill(['patterns' => $patterns]);

        $this->assertLessThanOrEqual(3, count($result['next_run_injection_rules']));
    }

    // ── pattern_quality_score ─────────────────────────────────────────────────

    public function test_quality_score_is_one_when_all_reusable(): void
    {
        $result = $this->library->distill($this->input($this->pattern(), $this->pattern()));

        $this->assertEqualsWithDelta(1.0, $result['pattern_quality_score'], 0.0001);
    }

    public function test_quality_score_is_zero_when_all_retired(): void
    {
        $result = $this->library->distill($this->input(
            $this->pattern(['give_back_count' => 3]),
            $this->pattern(['give_back_count' => 3]),
        ));

        $this->assertEqualsWithDelta(0.0, $result['pattern_quality_score'], 0.0001);
    }

    public function test_quality_score_is_half_when_one_of_two_retired(): void
    {
        $result = $this->library->distill($this->input(
            $this->pattern(['give_back_count' => 3]),
            $this->pattern(['success_count'   => 5]),
        ));

        $this->assertEqualsWithDelta(0.5, $result['pattern_quality_score'], 0.0001);
    }

    public function test_quality_score_is_one_when_no_patterns(): void
    {
        $result = $this->library->distill(['patterns' => []]);

        $this->assertEqualsWithDelta(1.0, $result['pattern_quality_score'], 0.0001);
    }

    // ── provider_safe_summary ─────────────────────────────────────────────────

    public function test_provider_safe_summary_is_non_empty(): void
    {
        $result = $this->library->distill($this->input($this->pattern()));

        $this->assertNotEmpty($result['provider_safe_summary']);
    }

    // ── All six valid types ───────────────────────────────────────────────────

    public function test_all_valid_types_are_accepted(): void
    {
        $result = $this->library->distill($this->input(
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_DECISION_PATTERN]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_FAILURE_CHECK]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_TASK_SHAPING_HEURISTIC]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_ANTI_PROXY_RULE]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_ESCALATION_TRIGGER]),
            $this->pattern(['type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_SIMPLIFICATION_RULE]),
        ));

        $this->assertCount(6, $result['reusable_patterns']);
        $this->assertSame([], $result['rejected_patterns']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_patterns_returns_empty_reusable_and_retired(): void
    {
        $result = $this->library->distill(['patterns' => []]);

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame([], $result['retired_patterns']);
        $this->assertSame([], $result['rejected_patterns']);
    }
}
