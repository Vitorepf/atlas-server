<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierDistillationPatternLibrary;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierDistillationPatternLibraryTest extends TestCase
{
    private function library(): AtlasExternalBrainFrontierDistillationPatternLibrary
    {
        return new AtlasExternalBrainFrontierDistillationPatternLibrary;
    }

    private function pattern(array $overrides = []): array
    {
        return array_merge([
            'pattern_id' => 'p1',
            'type' => AtlasExternalBrainFrontierDistillationPatternLibrary::TYPE_DECISION_PATTERN,
            'abstract_rule' => 'prefer smallest diff that satisfies the acceptance criteria',
            'success_count' => 1,
        ], $overrides);
    }

    // ── AC2: each provider-unsafe / invalid-type signal is rejected before reuse ──

    public function test_each_rejection_signal_is_detected(): void
    {
        $cases = [
            'contains_provider_prompt' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_PROMPT,
            'contains_private_trace' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PRIVATE_TRACE,
            'contains_provider_session_id' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_SESSION_ID,
            'contains_unredacted_prompt_fragment' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_UNREDACTED_PROMPT_FRAGMENT,
            'is_one_off_output' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_ONE_OFF_OUTPUT,
            'is_provider_specific_trick' => AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_PROVIDER_SPECIFIC_TRICK,
        ];

        foreach ($cases as $flag => $expectedReason) {
            $result = $this->library()->distill(['patterns' => [$this->pattern([$flag => true])]]);

            $this->assertSame([], $result['reusable_patterns'], "flag: {$flag}");
            $this->assertSame($expectedReason, $result['rejected_patterns'][0]['rejection_reason'], "flag: {$flag}");
        }
    }

    public function test_invalid_pattern_type_is_rejected(): void
    {
        $result = $this->library()->distill(['patterns' => [$this->pattern(['type' => 'not_a_real_type'])]]);

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::REJECTION_INVALID_TYPE,
            $result['rejected_patterns'][0]['rejection_reason'],
        );
    }

    // ── AC3: reusable patterns sorted by success_count; scaffold candidate gated ──

    public function test_reusable_patterns_sorted_by_success_count_descending(): void
    {
        $result = $this->library()->distill(['patterns' => [
            $this->pattern(['pattern_id' => 'low', 'success_count' => 2]),
            $this->pattern(['pattern_id' => 'high', 'success_count' => 9]),
        ]]);

        $this->assertSame(['high', 'low'], array_column($result['reusable_patterns'], 'pattern_id'));
    }

    public function test_scaffold_candidate_appears_only_with_distilled_scaffold_and_repeated_success(): void
    {
        $noScaffold = $this->library()->distill(['patterns' => [
            $this->pattern(['success_count' => 5]),
        ]]);
        $this->assertNull($noScaffold['reusable_patterns'][0]['provider_agnostic_scaffold_candidate']);

        $scaffoldButLowSuccess = $this->library()->distill(['patterns' => [
            $this->pattern(['success_count' => 1, 'distilled_scaffold' => 'do X then Y']),
        ]]);
        $this->assertNull($scaffoldButLowSuccess['reusable_patterns'][0]['provider_agnostic_scaffold_candidate']);

        $qualifies = $this->library()->distill(['patterns' => [
            $this->pattern(['success_count' => 2, 'distilled_scaffold' => 'do X then Y']),
        ]]);
        $this->assertNotNull($qualifies['reusable_patterns'][0]['provider_agnostic_scaffold_candidate']);
        $this->assertSame('do X then Y', $qualifies['reusable_patterns'][0]['provider_agnostic_scaffold_candidate']['scaffold']);
    }

    // ── AC4: retirement rules and capped injection rules ──────────────────────

    public function test_high_give_back_count_retires_pattern(): void
    {
        $result = $this->library()->distill(['patterns' => [
            $this->pattern(['give_back_count' => 3]),
        ]]);

        $this->assertSame([], $result['reusable_patterns']);
        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_GIVE_BACK_COUNT,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    public function test_high_give_back_rate_retires_pattern(): void
    {
        $result = $this->library()->distill(['patterns' => [
            $this->pattern(['success_count' => 2, 'give_back_count' => 2, 'low_value_count' => 0]),
        ]]);

        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_GIVE_BACK_RATE,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    public function test_zero_success_with_low_value_retires_pattern(): void
    {
        $result = $this->library()->distill(['patterns' => [
            $this->pattern(['success_count' => 0, 'low_value_count' => 2]),
        ]]);

        $this->assertSame(
            AtlasExternalBrainFrontierDistillationPatternLibrary::RETIRE_REASON_ZERO_VALUE,
            $result['retired_patterns'][0]['retire_reason'],
        );
    }

    public function test_injection_rules_are_capped(): void
    {
        $patterns = [];
        for ($i = 0; $i < 6; $i++) {
            $patterns[] = $this->pattern(['pattern_id' => "p{$i}", 'success_count' => $i + 1]);
        }

        $result = $this->library()->distill(['patterns' => $patterns]);

        $this->assertCount(3, $result['next_run_injection_rules']);
    }
}
