<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenishment;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneCompletionAuditReader;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive completion-audit interpretation concern extracted from
 * AgentControlPlaneTaskAutoReplenishmentService into AgentControlPlaneCompletionAuditReader.
 *
 * Six methods migrated verbatim:
 *  - completionAuditPayload: walk five known nested paths and return the first non-empty array.
 *  - completionAuditFailedCriteria: aggregate IDs from four schema variants, de-duplicate.
 *  - completionAuditFailedCriterionDetails: project details keyed by id (idempotent merge).
 *  - completionAuditCriterionRequiresOperator: true for the three operator-only criteria.
 *  - completionAuditOperatorHandoffReason: human-readable reason per operator-only criterion.
 *  - operatorHandoffNextAction: "what the operator must do next" per operator-only criterion.
 *
 * Pure / stateless / zero Laravel surface — pure PHPUnit suffices.
 */
final class AgentControlPlaneCompletionAuditReaderTest extends TestCase
{
    private AgentControlPlaneCompletionAuditReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new AgentControlPlaneCompletionAuditReader;
    }

    // --- completionAuditPayload --------------------------------------

    public function test_payload_returns_first_non_empty_root_path(): void
    {
        $audit = [
            'agent_control_plane_atlas_self_construction_os_completion_audit' => [
                'failed_criteria' => ['from-root'],
            ],
        ];

        $this->assertSame(
            ['failed_criteria' => ['from-root']],
            $this->reader->completionAuditPayload($audit),
        );
    }

    public function test_payload_returns_first_non_empty_nested_path(): void
    {
        // The five paths are tried in order. The first NON-EMPTY array wins.
        $audit = [
            'current_completion_audit' => ['failed_criteria' => ['from-current']],
        ];

        $this->assertSame(
            ['failed_criteria' => ['from-current']],
            $this->reader->completionAuditPayload($audit),
        );
    }

    public function test_payload_returns_input_as_fallback_when_no_path_matches(): void
    {
        $audit = ['only_root' => 'no_nested_audit_here'];

        $this->assertSame($audit, $this->reader->completionAuditPayload($audit));
    }

    public function test_payload_skips_empty_array_along_its_path_walk(): void
    {
        // First path is an empty array — must skip and continue to the next non-empty one.
        $audit = [
            'agent_control_plane_atlas_self_construction_os_completion_audit' => [],
            'completion_audit' => ['failed_criteria' => ['from-completion-audit']],
        ];

        $this->assertSame(
            ['failed_criteria' => ['from-completion-audit']],
            $this->reader->completionAuditPayload($audit),
        );
    }

    // --- completionAuditFailedCriteria -------------------------------

    public function test_failed_criteria_aggregates_from_failed_criteria_string_list(): void
    {
        $audit = ['failed_criteria' => ['a', 'b', 'c']];

        $this->assertSame(['a', 'b', 'c'], $this->reader->completionAuditFailedCriteria($audit));
    }

    public function test_failed_criteria_aggregates_from_failed_criteria_detailed_entries(): void
    {
        $audit = [
            'failed_criteria' => ['a'],
            'failed_criteria_detailed' => [
                ['id' => 'b'],
                ['id' => 'c'],
                ['id' => ''], // empty id is filtered out
            ],
        ];

        $this->assertSame(['a', 'b', 'c'], $this->reader->completionAuditFailedCriteria($audit));
    }

    public function test_failed_criteria_aggregates_from_five_nested_blocker_paths(): void
    {
        $audit = [
            'current_blocks_completion_criteria' => ['x', 'y'],
            'operator_handoff_packet' => [
                'current_blocks_completion_criteria' => ['z'],
            ],
            'blocker_classification' => [
                'human_blockers' => ['h1'],
                'real_provider_blockers' => ['r1'],
                'technical_blockers' => ['t1'],
            ],
        ];

        $out = $this->reader->completionAuditFailedCriteria($audit);
        sort($out);
        $this->assertSame(['h1', 'r1', 't1', 'x', 'y', 'z'], $out);
    }

    public function test_failed_criteria_deduplicates_across_sources(): void
    {
        $audit = [
            'failed_criteria' => ['a', 'b'],
            'failed_criteria_detailed' => [['id' => 'b'], ['id' => 'c']],
            'blocker_classification' => [
                'human_blockers' => ['a', 'c', 'd'],
            ],
        ];

        $out = $this->reader->completionAuditFailedCriteria($audit);
        sort($out);
        $this->assertSame(['a', 'b', 'c', 'd'], $out, 'union of all sources, deduped');
    }

    public function test_failed_criteria_filters_empty_strings_and_null(): void
    {
        // array_filter WITHOUT a callback removes every falsy value — so `''` AND `null` are
        // both dropped. (string) null is `''`, which is then filtered. Non-string falsy values
        // (null) get the same treatment.
        $audit = ['failed_criteria' => ['a', '', 'b', null]];

        $this->assertSame(['a', 'b'], $out = $this->reader->completionAuditFailedCriteria($audit));
    }
    // --- completionAuditFailedCriterionDetails -----------------------

    public function test_failed_criterion_details_keys_by_id_from_three_nested_paths(): void
    {
        $audit = [
            'failed_criteria_detailed' => [
                ['id' => 'c1', 'note' => 'from-detailed'],
            ],
            'blockers' => [
                ['id' => 'c2', 'note' => 'from-blockers'],
            ],
            'operator_handoff_packet' => [
                'blockers' => [
                    ['id' => 'c3', 'note' => 'from-handoff'],
                ],
            ],
        ];

        $details = $this->reader->completionAuditFailedCriterionDetails($audit);

        $this->assertCount(3, $details);
        $this->assertSame('from-detailed', $details['c1']['note']);
        $this->assertSame('from-blockers', $details['c2']['note']);
        $this->assertSame('from-handoff', $details['c3']['note']);
    }

    public function test_failed_criterion_details_first_source_wins_on_idempotent_merge(): void
    {
        $audit = [
            'failed_criteria_detailed' => [
                ['id' => 'c1', 'note' => 'first-source-wins'],
            ],
            'blockers' => [
                ['id' => 'c1', 'note' => 'second-source-loses'],
            ],
        ];

        $details = $this->reader->completionAuditFailedCriterionDetails($audit);

        $this->assertSame('first-source-wins', $details['c1']['note']);
    }

    public function test_failed_criterion_details_filters_empty_ids(): void
    {
        $audit = [
            'failed_criteria_detailed' => [
                ['id' => ''], // empty id, filtered
                ['note' => 'no-id'], // also filtered (no id key)
            ],
        ];

        $details = $this->reader->completionAuditFailedCriterionDetails($audit);

        $this->assertSame([], $details);
    }

    // --- completionAuditCriterionRequiresOperator ---------------------

    public function test_criterion_requires_operator_for_three_known_criteria(): void
    {
        foreach (AgentControlPlaneCompletionAuditReader::OPERATOR_ONLY_CRITERIA as $criterion) {
            $this->assertTrue(
                $this->reader->completionAuditCriterionRequiresOperator($criterion),
                "$criterion must require operator",
            );
        }
    }

    public function test_criterion_does_not_require_operator_for_other_criteria(): void
    {
        $this->assertFalse($this->reader->completionAuditCriterionRequiresOperator('some_other_criterion'));
        $this->assertFalse($this->reader->completionAuditCriterionRequiresOperator(''));
    }

    // --- completionAuditOperatorHandoffReason ------------------------

    public function test_operator_handoff_reason_is_canonical_per_criterion(): void
    {
        $this->assertSame(
            'requires_operator_signed_runtime_promotion_receipt_before_runtime_gap_can_close',
            $this->reader->completionAuditOperatorHandoffReason('runtime_gap_matrix_all_runtime_y'),
        );
        $this->assertSame(
            'requires_human_signed_os_completion_receipt_after_runtime_and_real_provider_smoke_are_green',
            $this->reader->completionAuditOperatorHandoffReason('human_signed_os_complete_receipt_present'),
        );
        $this->assertSame(
            'requires_operator_run_real_provider_smoke_outside_atlas_and_persist_evidence',
            $this->reader->completionAuditOperatorHandoffReason('end_to_end_real_provider_smoke_green'),
        );
    }

    public function test_operator_handoff_reason_is_empty_string_for_unknown_criterion(): void
    {
        $this->assertSame('', $this->reader->completionAuditOperatorHandoffReason('not_an_operator_criterion'));
        $this->assertSame('', $this->reader->completionAuditOperatorHandoffReason(''));
    }

    // --- operatorHandoffNextAction ----------------------------------

    public function test_next_action_is_canonical_per_operator_only_criterion(): void
    {
        $this->assertSame(
            'run_runtime_promotion_endgame_and_persist_operator_signed_runtime_promotion_receipt',
            $this->reader->operatorHandoffNextAction('runtime_gap_matrix_all_runtime_y'),
        );
        $this->assertSame(
            'persist_human_completion_receipt_only_after_runtime_promotion_and_real_provider_smoke_are_green',
            $this->reader->operatorHandoffNextAction('human_signed_os_complete_receipt_present'),
        );
        $this->assertSame(
            'run_real_provider_smoke_outside_atlas_then_persist_smoke_certification_evidence',
            $this->reader->operatorHandoffNextAction('end_to_end_real_provider_smoke_green'),
        );
    }

    public function test_next_action_falls_back_to_generic_for_unknown_criterion(): void
    {
        $this->assertSame(
            'operator_review_required_before_replenishing_worker_claimable_task',
            $this->reader->operatorHandoffNextAction('not_an_operator_criterion'),
        );
    }

    // --- OPERATOR_ONLY_CRITERIA constant ----------------------------

    public function test_operator_only_criteria_constant_lists_exactly_three_known_criteria(): void
    {
        $this->assertSame(
            [
                'runtime_gap_matrix_all_runtime_y',
                'human_signed_os_complete_receipt_present',
                'end_to_end_real_provider_smoke_green',
            ],
            AgentControlPlaneCompletionAuditReader::OPERATOR_ONLY_CRITERIA,
        );
    }
}
