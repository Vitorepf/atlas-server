<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierExhaustionEscalationLadder;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFrontierExhaustionEscalationLadderTest extends TestCase
{
    public function test_declining_local_yield_with_quota_escalates_to_all_deeper_fronts(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 20,
        ]);

        self::assertFalse($result['exhausted']);
        self::assertContains('cross_file_invariant_scan', $result['next_fronts']);
        self::assertContains('design_path_mining', $result['next_fronts']);
        self::assertContains('simplification_candidate_search', $result['next_fronts']);
        self::assertContains('research_to_task_digest', $result['next_fronts']);
    }

    public function test_repeated_duplicate_findings_suppress_vein_and_escalate_with_duplicate_yield_reason(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 10,
            'quota_remaining' => 20,
            'duplicate_yield_ratio' => 0.8,
        ]);

        self::assertFalse($result['exhausted']);
        self::assertNotEmpty($result['next_fronts']);
        self::assertStringContainsString('duplicate_yield', $result['reasons'][0]);
    }

    public function test_all_fronts_attempted_with_evidence_returns_exhausted_with_terminal_evidence_requirement(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 0,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => [
                'local_grep_bug_hunt',
                'cross_file_invariant_scan',
                'design_path_mining',
                'simplification_candidate_search',
                'research_to_task_digest',
            ],
        ]);

        self::assertTrue($result['exhausted']);
        self::assertNotEmpty($result['evidence_required_for_terminal_stop']);
    }

    public function test_partial_attempted_fronts_does_not_exhaust(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => ['local_grep_bug_hunt'],
        ]);

        self::assertFalse($result['exhausted']);
    }

    // ── AC3: output includes next_front, suppressed_fronts, attempted_with_evidence, missing_evidence_fronts ──

    public function test_output_includes_next_front_and_evidence_tracking_fields(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 1,
            'quota_remaining' => 20,
            'attempted_fronts_with_evidence' => ['local_grep_bug_hunt'],
        ]);

        self::assertArrayHasKey('next_front', $result);
        self::assertArrayHasKey('suppressed_fronts', $result);
        self::assertArrayHasKey('attempted_with_evidence', $result);
        self::assertArrayHasKey('missing_evidence_fronts', $result);
        self::assertSame($result['next_fronts'][0] ?? null, $result['next_front']);
        self::assertSame(['local_grep_bug_hunt'], $result['attempted_with_evidence']);
    }

    public function test_duplicate_yield_suppresses_local_front_in_suppressed_fronts(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 10,
            'quota_remaining' => 20,
            'duplicate_yield_ratio' => 0.8,
        ]);

        self::assertContains('local_grep_bug_hunt', $result['suppressed_fronts']);
        self::assertNotContains('local_grep_bug_hunt', $result['next_fronts']);
    }

    public function test_exhausted_returns_null_next_front(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->evaluate([
            'local_findings_per_wave' => 0,
            'quota_remaining' => 5,
            'attempted_fronts_with_evidence' => [
                'local_grep_bug_hunt',
                'cross_file_invariant_scan',
                'design_path_mining',
                'simplification_candidate_search',
                'research_to_task_digest',
            ],
        ]);

        self::assertNull($result['next_front']);
        self::assertSame([], $result['suppressed_fronts']);
    }

    // ── AC: named escalation ladder ────────────────────────────────────────────

    public function test_ladder_orders_deeper_local_probe_through_frontier_rerun(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalationLadder([]);

        self::assertSame([
            'deeper_local_probe',
            'research_transfer',
            'simplification_path',
            'counterfactual_review',
            'frontier_rerun',
        ], $result['ordered_actions']);
    }

    public function test_frontier_rerun_is_last_unless_risk_or_novelty_demands_immediate_frontier(): void
    {
        $normal = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalationLadder(['task_risk' => 'low', 'task_novelty' => 'low']);
        self::assertSame('frontier_rerun', end($normal['ordered_actions']));

        $highRisk = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalationLadder(['task_risk' => 'high']);
        self::assertSame('frontier_rerun', $highRisk['ordered_actions'][0]);
        self::assertCount(1, $highRisk['ordered_actions']);

        $highNovelty = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalationLadder(['task_novelty' => 'high']);
        self::assertSame('frontier_rerun', $highNovelty['ordered_actions'][0]);
    }

    public function test_escalation_ladder_output_includes_ordered_actions_skipped_actions_and_rationale(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalationLadder(['task_risk' => 'high']);

        self::assertArrayHasKey('ordered_actions', $result);
        self::assertArrayHasKey('skipped_actions', $result);
        self::assertArrayHasKey('escalation_rationale', $result);
        self::assertNotEmpty($result['skipped_actions']);
        self::assertNotEmpty($result['escalation_rationale']);
    }

    // ── AC: low initial yield recommends second_pass_search first ──────────────

    public function test_low_initial_yield_recommends_second_pass_search_first(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.10,
        ]);

        self::assertSame(
            AtlasExternalBrainFrontierExhaustionEscalationLadder::RUNG2_SECOND_PASS_SEARCH,
            $result['recommendation'],
        );
    }

    public function test_healthy_initial_yield_recommends_nothing(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.90,
        ]);

        self::assertNull($result['recommendation']);
    }

    // ── AC: repeated low second-pass yield recommends outcome_mining or simplification ──

    public function test_repeated_low_second_pass_yield_recommends_outcome_mining_by_default(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.10,
            'second_pass_yield' => 0.05,
            'attempted_rungs_with_evidence' => ['second_pass_search'],
        ]);

        self::assertSame(
            AtlasExternalBrainFrontierExhaustionEscalationLadder::RUNG2_OUTCOME_MINING,
            $result['recommendation'],
        );
    }

    public function test_repeated_low_second_pass_yield_recommends_simplification_when_structurally_complex(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.10,
            'second_pass_yield' => 0.05,
            'attempted_rungs_with_evidence' => ['second_pass_search'],
            'structurally_complex' => true,
        ]);

        self::assertSame(
            AtlasExternalBrainFrontierExhaustionEscalationLadder::RUNG2_SIMPLIFICATION,
            $result['recommendation'],
        );
    }

    // ── AC: honest_stop appears only after all ladder rungs are exhausted with evidence ──

    public function test_honest_stop_only_after_all_rungs_attempted_with_evidence(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.05,
            'second_pass_yield' => 0.05,
            'attempted_rungs_with_evidence' => ['second_pass_search', 'outcome_mining', 'simplification'],
        ]);

        self::assertSame(
            AtlasExternalBrainFrontierExhaustionEscalationLadder::RUNG2_HONEST_STOP,
            $result['recommendation'],
        );
    }

    public function test_honest_stop_not_reached_with_partial_rungs_attempted(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([
            'initial_yield' => 0.05,
            'second_pass_yield' => 0.05,
            'attempted_rungs_with_evidence' => ['second_pass_search', 'outcome_mining'],
        ]);

        self::assertNotSame(
            AtlasExternalBrainFrontierExhaustionEscalationLadder::RUNG2_HONEST_STOP,
            $result['recommendation'],
        );
    }

    public function test_escalate_exhaustion_output_has_required_keys(): void
    {
        $result = (new AtlasExternalBrainFrontierExhaustionEscalationLadder)->escalateExhaustion([]);

        self::assertSame(AtlasExternalBrainFrontierExhaustionEscalationLadder::SCHEMA, $result['schema']);
        foreach (['recommendation', 'attempted_rungs_with_evidence', 'remaining_rungs', 'reasons'] as $key) {
            self::assertArrayHasKey($key, $result);
        }
        self::assertNotEmpty($result['reasons']);
    }
}
