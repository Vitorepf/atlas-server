<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneWorkerEligibilityGuard;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive worker-eligibility validation concern extracted from
 * AgentControlPlaneTerminalWorkerBootstrapService into AgentControlPlaneWorkerEligibilityGuard.
 *
 * One method migrated verbatim:
 *  - workerEligibilityGuard: read claimable records from the queue, scan each for the seven
 *    violation codes, and emit the guard envelope (status + eligible count + violations + stable
 *    hash + non-execution guarantees).
 *
 * Pure core — but depends on the queue repo for the records. We inject an anonymous stub manager
 * whose list() returns a caller-supplied list of records, so the test runs without Laravel's queue
 * disk / orchestrator / etc.
 */
final class AgentControlPlaneWorkerEligibilityGuardTest extends TestCase
{
    /**
     * Build a queue repo stub whose list() returns the supplied records for ANY filter.
     */
    /**
     * Build a guard whose `readClaimableRecords()` hook returns the supplied records for ANY
     * filter (bypassing the {@see AgentControlPlaneTaskPacketQueueRepository} instance). We subclass
     * the guard (it's NOT final) and override the protected hook the guard exposes for tests.
     * The guard's queue repo property is constructed by the parent but never consulted because
     * the hook short-circuits to our pre-canned records.
     *
     * @param  list<array<string,mixed>>  $records
     */
    private function guardWith(array $records): AgentControlPlaneWorkerEligibilityGuard
    {
        return new class($records) extends AgentControlPlaneWorkerEligibilityGuard
        {
            /** @param  list<array<string,mixed>>  $records */
            public function __construct(private readonly array $records)
            {
                // intentionally do NOT call parent::__construct (would require a real queue repo
                // and a disk root). The records are passed in directly via the hook override.
            }

            protected function readClaimableRecords(array $queueTags, int $limit = 0): array
            {
                return $this->records;
            }
        };
    }

    public function test_returns_available_status_when_no_records(): void
    {
        $guard = $this->guardWith([])->workerEligibilityGuard([]);

        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_worker_bootstrap_worker_task_eligibility_guard.v1',
            $guard['schema_version'],
        );
        $this->assertSame('available', $guard['status']);
        $this->assertSame([], $guard['queue_tags']);
        $this->assertSame(0, $guard['checked_claimable_task_count']);
        $this->assertSame(0, $guard['eligible_claimable_task_count']);
        $this->assertSame([], $guard['blocked_reasons']);
        $this->assertSame([], $guard['violations']);
        $this->assertSame(0, $guard['violation_count']);
        $this->assertFalse($guard['can_claim_after_guard']);
    }

    public function test_returns_available_when_record_is_clean(): void
    {
        $record = ['task_packet_id' => 'TP1', 'task_packet' => ['continuation_context' => []]];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertSame('available', $guard['status']);
        $this->assertSame(1, $guard['checked_claimable_task_count']);
        $this->assertSame(1, $guard['eligible_claimable_task_count']);
        $this->assertTrue($guard['can_claim_after_guard']);
        $this->assertSame([], $guard['violations']);
    }

    public function test_flags_claimable_task_not_worker_executable(): void
    {
        $record = [
            'task_packet_id' => 'TP1',
            'task_packet' => [
                'continuation_context' => ['worker_executable' => false],
            ],
        ];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertContains(
            ['code' => 'claimable_task_not_worker_executable', 'task_packet_id' => 'TP1'],
            $guard['violations'],
        );
        $this->assertSame(1, $guard['violation_count']);
        $this->assertSame(0, $guard['eligible_claimable_task_count']);
        $this->assertSame('blocked', $guard['status'], 'no eligible records and violations present => blocked');
        $this->assertFalse($guard['can_claim_after_guard']);
    }

    public function test_flags_claimable_task_requires_operator_handoff(): void
    {
        $record = [
            'task_packet_id' => 'TP1',
            'task_packet' => [
                'continuation_context' => ['operator_handoff_required' => true],
            ],
        ];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertContains(
            ['code' => 'claimable_task_requires_operator_handoff', 'task_packet_id' => 'TP1'],
            $guard['violations'],
        );
    }

    public function test_flags_operator_only_completion_criteria_reference(): void
    {
        // Each of the three operator-only references must produce a violation with the reference echoed.
        foreach (['runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present', 'end_to_end_real_provider_smoke_green'] as $ref) {
            $record = [
                'task_packet_id' => 'TP1',
                'task_packet' => [
                    'continuation_context' => ['auto_replenishment_reference' => $ref],
                ],
            ];

            $guard = $this->guardWith([$record])->workerEligibilityGuard([]);
            $this->assertContains(
                ['code' => 'claimable_task_references_operator_only_completion_blocker', 'task_packet_id' => 'TP1', 'reference' => $ref],
                $guard['violations'],
                "reference '$ref' must trigger operator-only completion blocker violation",
            );
        }
    }

    public function test_flags_each_runtime_flag_when_true(): void
    {
        // One record with all seven runtime-allowed flags set true => 7 violations of the runtime-flag type.
        $runtimeFlags = [
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'runtime_execution_allowed',
            'completion_real_allowed',
        ];
        $record = ['task_packet_id' => 'TP1', 'task_packet' => []] + array_fill_keys($runtimeFlags, true);

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $flagViolations = array_values(array_filter(
            $guard['violations'],
            static fn (array $v): bool => (string) ($v['code'] ?? '') === 'claimable_task_runtime_flag_true',
        ));
        $this->assertCount(7, $flagViolations, 'all 7 runtime-allowed flags must produce a violation');

        // Each violation names the flag that was set. Sort both lists because the guard
        // walks the RUNTIME_FLAG_NAMES constant in declaration order, but the assertion is just
        // about completeness — order is incidental.
        $flaggedNames = array_map(static fn (array $v): string => (string) ($v['flag'] ?? ''), $flagViolations);
        sort($flaggedNames);
        $expectedSorted = $runtimeFlags;
        sort($expectedSorted);
        $this->assertSame($expectedSorted, $flaggedNames, 'all 7 runtime flags produced a violation');
    }

    public function test_returns_available_with_non_worker_candidates_when_some_eligible_some_not(): void
    {
        $clean = ['task_packet_id' => 'TP-CLEAN', 'task_packet' => ['continuation_context' => []]];
        $broken = [
            'task_packet_id' => 'TP-BROKEN',
            'task_packet' => [
                'continuation_context' => ['worker_executable' => false],
            ],
        ];

        $guard = $this->guardWith([$clean, $broken])->workerEligibilityGuard([]);

        $this->assertSame(2, $guard['checked_claimable_task_count']);
        $this->assertSame(1, $guard['eligible_claimable_task_count']);
        $this->assertSame('available_with_non_worker_candidates', $guard['status']);
        $this->assertTrue($guard['can_claim_after_guard']);
    }

    public function test_blocked_reasons_lists_unique_codes(): void
    {
        $record = [
            'task_packet_id' => 'TP1',
            'task_packet' => [
                'continuation_context' => [
                    'worker_executable' => false,
                    'operator_handoff_required' => true,
                ],
            ],
        ];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertCount(2, $guard['violations']);
        $this->assertCount(2, $guard['blocked_reasons']);
        $this->assertContains('claimable_task_not_worker_executable', $guard['blocked_reasons']);
        $this->assertContains('claimable_task_requires_operator_handoff', $guard['blocked_reasons']);
    }

    public function test_queue_tags_echoed_verbatim(): void
    {
        $guard = $this->guardWith([])->workerEligibilityGuard(['tag-a', 'tag-b']);

        $this->assertSame(['tag-a', 'tag-b'], $guard['queue_tags']);
    }

    public function test_emits_stable_hash(): void
    {
        $record = ['task_packet_id' => 'TP1', 'task_packet' => ['continuation_context' => []]];

        $a = $this->guardWith([$record])->workerEligibilityGuard([]);
        $b = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertSame($a['worker_task_eligibility_guard_hash'], $b['worker_task_eligibility_guard_hash']);
        $this->assertNotSame('', $a['worker_task_eligibility_guard_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['worker_task_eligibility_guard_hash']);
    }

    public function test_emits_five_non_execution_guarantees(): void
    {
        $guard = $this->guardWith([])->workerEligibilityGuard([]);

        $this->assertCount(5, $guard['non_execution_guarantees']);
        $this->assertContains('worker_task_eligibility_guard_does_not_claim_tasks', $guard['non_execution_guarantees']);
        $this->assertContains('worker_task_eligibility_guard_does_not_dispatch_work', $guard['non_execution_guarantees']);
    }

    public function test_ignores_empty_task_packet_id_when_marking_ineligible(): void
    {
        // A record with empty task_packet_id still triggers a violation BUT must NOT appear in
        // ineligibleTaskIds (which is keyed by task_packet_id).
        $record = [
            'task_packet_id' => '',
            'task_packet' => [
                'continuation_context' => ['worker_executable' => false],
            ],
        ];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $this->assertSame(1, $guard['violation_count']);
        // eligible_claimable_task_count is the count of records not in $ineligibleTaskIds.
        // Since task_packet_id is '' => NOT in the ineligible map => counted as eligible.
        // This is the byte-identical god-class behaviour (the `&& $taskPacketId !== ''` guard).
        $this->assertSame(1, $guard['eligible_claimable_task_count']);
    }

    public function test_runtime_flag_detected_in_continuation_context(): void
    {
        $record = [
            'task_packet_id' => 'TP-CTX',
            'task_packet' => [
                'continuation_context' => ['dispatch_allowed' => true],
            ],
        ];

        $guard = $this->guardWith([$record])->workerEligibilityGuard([]);

        $flagViolations = array_values(array_filter(
            $guard['violations'],
            static fn (array $v): bool => $v['code'] === 'claimable_task_runtime_flag_true' && $v['flag'] === 'dispatch_allowed',
        ));
        $this->assertNotEmpty($flagViolations, 'dispatch_allowed in continuation_context must produce a runtime-flag violation');
        $this->assertSame(0, $guard['eligible_claimable_task_count']);
    }

    public function test_ineligible_task_ids_exposed_in_output(): void
    {
        $records = [
            ['task_packet_id' => 'TP-BAD', 'task_packet' => ['continuation_context' => ['worker_executable' => false]]],
            ['task_packet_id' => 'TP-GOOD', 'task_packet' => ['continuation_context' => []]],
        ];

        $guard = $this->guardWith($records)->workerEligibilityGuard([]);

        $this->assertArrayHasKey('ineligible_task_ids', $guard);
        $this->assertSame(['TP-BAD'], $guard['ineligible_task_ids']);
    }

    public function test_ineligible_task_ids_is_sorted_deterministically(): void
    {
        $records = [
            ['task_packet_id' => 'TP-ZZZ', 'task_packet' => ['continuation_context' => ['operator_handoff_required' => true]]],
            ['task_packet_id' => 'TP-AAA', 'task_packet' => ['continuation_context' => ['worker_executable' => false]]],
        ];

        $guard = $this->guardWith($records)->workerEligibilityGuard([]);

        $this->assertSame(['TP-AAA', 'TP-ZZZ'], $guard['ineligible_task_ids']);
    }
}
