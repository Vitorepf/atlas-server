<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Replenishment;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneCompletionAuditReader;
use Tests\TestCase;

final class AgentControlPlaneCompletionAuditReaderTest extends TestCase
{
    private function reader(): AgentControlPlaneCompletionAuditReader
    {
        return new AgentControlPlaneCompletionAuditReader;
    }

    // ── completionAuditPayload path precedence ──────────────────────────────────

    public function test_completion_audit_payload_prefers_top_priority_path_over_lower_ones(): void
    {
        $payload = [
            'agent_control_plane_atlas_self_construction_os_completion_audit' => ['from' => 'top'],
            'current_completion_audit' => ['from' => 'current'],
            'completion_audit' => ['from' => 'plain'],
        ];

        $result = $this->reader()->completionAuditPayload($payload);

        self::assertSame('top', $result['from']);
    }

    public function test_completion_audit_payload_falls_through_to_next_path_when_earlier_is_empty(): void
    {
        $payload = [
            'current_completion_audit' => [],
            'completion_audit' => ['from' => 'plain'],
        ];

        $result = $this->reader()->completionAuditPayload($payload);

        self::assertSame('plain', $result['from']);
    }

    public function test_completion_audit_payload_falls_back_to_input_when_no_path_matches(): void
    {
        $payload = ['some_other_key' => 'value'];

        self::assertSame($payload, $this->reader()->completionAuditPayload($payload));
    }

    // ── failed criteria aggregation & detail precedence ─────────────────────────

    public function test_failed_criteria_aggregates_and_deduplicates_across_all_schema_variants(): void
    {
        $payload = [
            'failed_criteria' => ['a'],
            'failed_criteria_detailed' => [['id' => 'b']],
            'current_blocks_completion_criteria' => ['a', 'c'],
            'blocker_classification' => ['human_blockers' => ['d']],
        ];

        $criteria = $this->reader()->completionAuditFailedCriteria($payload);

        self::assertSame(['a', 'b', 'c', 'd'], $criteria);
    }

    public function test_failed_criterion_details_first_source_wins_and_is_not_clobbered(): void
    {
        $payload = [
            'failed_criteria_detailed' => [['id' => 'x', 'detail' => 'from_detailed']],
            'blockers' => [['id' => 'x', 'detail' => 'from_blockers']],
        ];

        $details = $this->reader()->completionAuditFailedCriterionDetails($payload);

        self::assertSame('from_detailed', $details['x']['detail']);
    }

    public function test_failed_criterion_details_merges_new_ids_from_operator_handoff_blockers(): void
    {
        $payload = [
            'operator_handoff_packet' => [
                'blockers' => [['id' => 'y', 'detail' => 'from_operator_handoff']],
            ],
        ];

        $details = $this->reader()->completionAuditFailedCriterionDetails($payload);

        self::assertSame('from_operator_handoff', $details['y']['detail']);
    }

    // ── operator-only handoff reasons ───────────────────────────────────────────

    public function test_operator_only_criteria_require_operator_and_produce_specific_handoff_reason(): void
    {
        $reader = $this->reader();

        foreach (AgentControlPlaneCompletionAuditReader::OPERATOR_ONLY_CRITERIA as $criterion) {
            self::assertTrue($reader->completionAuditCriterionRequiresOperator($criterion));
            self::assertNotSame('', $reader->completionAuditOperatorHandoffReason($criterion));
            self::assertNotSame(
                'operator_review_required_before_replenishing_worker_claimable_task',
                $reader->operatorHandoffNextAction($criterion),
            );
        }
    }

    public function test_ordinary_criterion_does_not_require_operator_and_has_no_handoff_reason(): void
    {
        $reader = $this->reader();

        self::assertFalse($reader->completionAuditCriterionRequiresOperator('ordinary_technical_criterion'));
        self::assertSame('', $reader->completionAuditOperatorHandoffReason('ordinary_technical_criterion'));
        self::assertSame(
            'operator_review_required_before_replenishing_worker_claimable_task',
            $reader->operatorHandoffNextAction('ordinary_technical_criterion'),
        );
    }

    // ── poisonFamilies grouping / repair hints / min-count / success-ignored ────

    public function test_poison_families_only_reported_once_min_count_reached_and_success_ignored(): void
    {
        $records = [
            ['outcome' => 'give_back', 'give_back_reason' => 'forbidden_target', 'task_packet_id' => 'p1'],
            ['outcome' => 'success', 'give_back_reason' => 'forbidden_target', 'task_packet_id' => 'p2'],
        ];

        self::assertSame([], $this->reader()->poisonFamilies($records, 2)['poison_families']);

        $records[] = ['outcome' => 'give_back', 'give_back_reason' => 'forbidden_target', 'task_packet_id' => 'p3'];
        $families = $this->reader()->poisonFamilies($records, 2)['poison_families'];

        self::assertCount(1, $families);
        self::assertSame('forbidden_target', $families[0]['reason']);
        self::assertSame(2, $families[0]['count']);
        self::assertSame('resolve_forbidden_file_conflict', $families[0]['repair_hint']);
    }

    public function test_poison_families_are_deterministically_sorted_by_reason(): void
    {
        $records = [
            ['outcome' => 'give_back', 'give_back_reason' => 'zebra_test_only', 'task_packet_id' => 'p1'],
            ['outcome' => 'give_back', 'give_back_reason' => 'zebra_test_only', 'task_packet_id' => 'p2'],
            ['outcome' => 'give_back', 'give_back_reason' => 'alpha_scope', 'task_packet_id' => 'p3'],
            ['outcome' => 'give_back', 'give_back_reason' => 'alpha_scope', 'task_packet_id' => 'p4'],
        ];

        $families = $this->reader()->poisonFamilies($records, 2)['poison_families'];

        self::assertSame(['alpha_scope', 'zebra_test_only'], array_column($families, 'reason'));
        self::assertSame('run_atlas_task_repair_blocked', $families[0]['repair_hint']);
        self::assertSame('remove_or_rewire_test_only_survivors', $families[1]['repair_hint']);
    }

    // ── drain hint behavior ──────────────────────────────────────────────────────

    public function test_completion_velocity_replenish_hint_true_when_draining(): void
    {
        $hint = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 10,
            'completed_dry_run_count_previous' => 5,
            'claimable_per_active_worker' => 1.0,
        ]);

        self::assertTrue($hint['completion_velocity_replenish']);
        self::assertSame(5, $hint['completed_dry_run_delta']);
    }

    public function test_completion_velocity_replenish_hint_false_when_no_delta(): void
    {
        $hint = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 5,
            'completed_dry_run_count_previous' => 5,
            'claimable_per_active_worker' => 1.0,
        ]);

        self::assertFalse($hint['completion_velocity_replenish']);
    }

    public function test_completion_velocity_replenish_hint_false_when_above_threshold(): void
    {
        $hint = $this->reader()->completionVelocityReplenishHint([
            'completed_dry_run_count' => 10,
            'completed_dry_run_count_previous' => 5,
            'claimable_per_active_worker' => 10.0,
        ]);

        self::assertFalse($hint['completion_velocity_replenish']);
    }
}
