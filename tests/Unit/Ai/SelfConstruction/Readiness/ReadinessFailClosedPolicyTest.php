<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessFailClosedPolicy;
use PHPUnit\Framework\TestCase;

final class ReadinessFailClosedPolicyTest extends TestCase
{
    private ReadinessFailClosedPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReadinessFailClosedPolicy;
    }

    public function test_canonical_inner_status_passes_through_when_nothing_is_violated(): void
    {
        $decision = $this->policy->decideOuterStatus(
            [
                'status' => ReadinessFailClosedPolicy::STATUS_EXECUTABLE,
                'authority' => ['merge_allowed' => true],
                'evidence' => ['receipt_hash' => 'abc'],
            ],
            requiredFields: ['authority.merge_allowed'],
            requiredAuthorities: ['authority.merge_allowed'],
            requiredEvidenceKeys: ['evidence.receipt_hash'],
        );

        $this->assertSame(ReadinessFailClosedPolicy::STATUS_EXECUTABLE, $decision->status);
        $this->assertSame([], $decision->violations);
        $this->assertFalse($decision->isBlocked());
    }

    public function test_missing_required_field_blocks_with_typed_violation(): void
    {
        $decision = $this->policy->decideOuterStatus(
            ['status' => ReadinessFailClosedPolicy::STATUS_COMPLETED],
            requiredFields: ['completion.receipt_id'],
        );

        $this->assertTrue($decision->isBlocked());
        $this->assertSame(
            [['type' => ReadinessFailClosedPolicy::VIOLATION_MISSING_REQUIRED_FIELD, 'key' => 'completion.receipt_id']],
            $decision->violations,
        );
    }

    public function test_authority_must_be_strictly_true(): void
    {
        foreach ([1, '1', 'true', 0, null, []] as $notTrue) {
            $decision = $this->policy->decideOuterStatus(
                [
                    'status' => ReadinessFailClosedPolicy::STATUS_AUTHORIZED,
                    'promotion_allowed' => $notTrue,
                ],
                requiredAuthorities: ['promotion_allowed'],
            );

            $this->assertTrue($decision->isBlocked(), var_export($notTrue, true).' must not grant authority');
            $this->assertSame(ReadinessFailClosedPolicy::VIOLATION_AUTHORITY_NOT_GRANTED, $decision->violations[0]['type']);
        }
    }

    public function test_empty_evidence_blocks(): void
    {
        foreach ([null, '', []] as $empty) {
            $decision = $this->policy->decideOuterStatus(
                [
                    'status' => ReadinessFailClosedPolicy::STATUS_COMPLETED,
                    'evidence' => $empty,
                ],
                requiredEvidenceKeys: ['evidence'],
            );

            $this->assertTrue($decision->isBlocked());
            $this->assertSame(ReadinessFailClosedPolicy::VIOLATION_EVIDENCE_MISSING, $decision->violations[0]['type']);
        }
    }

    public function test_blockers_demote_outer_status_even_when_inner_claims_ready(): void
    {
        $decision = $this->policy->decideOuterStatus([
            'status' => ReadinessFailClosedPolicy::STATUS_SCHEMA_READY,
            'blockers' => ['runtime_schema_missing'],
        ]);

        $this->assertTrue($decision->isBlocked());
        $this->assertSame(
            [['type' => ReadinessFailClosedPolicy::VIOLATION_BLOCKERS_PRESENT, 'key' => 'blockers']],
            $decision->violations,
        );
    }

    public function test_blocking_reasons_also_count_as_blockers(): void
    {
        $decision = $this->policy->decideOuterStatus([
            'status' => ReadinessFailClosedPolicy::STATUS_EVIDENCE_PENDING,
            'blocking_reasons' => ['agent_control_plane_runtime_schema_missing'],
        ]);

        $this->assertTrue($decision->isBlocked());
    }

    public function test_unknown_or_absent_inner_status_fails_closed(): void
    {
        foreach ([[], ['status' => 'available'], ['status' => 'ready']] as $payload) {
            $decision = $this->policy->decideOuterStatus($payload);

            $this->assertTrue($decision->isBlocked(), json_encode($payload).' must fail closed');
            $this->assertSame(ReadinessFailClosedPolicy::VIOLATION_UNKNOWN_INNER_STATUS, $decision->violations[0]['type']);
        }
    }

    public function test_inner_blocked_stays_blocked_without_violations(): void
    {
        $decision = $this->policy->decideOuterStatus(['status' => ReadinessFailClosedPolicy::STATUS_BLOCKED]);

        $this->assertTrue($decision->isBlocked());
        $this->assertSame([], $decision->violations);
    }

    public function test_non_execution_guarantees_derive_once_from_the_subject(): void
    {
        $this->assertSame([
            'agent_control_plane_task_lease_recovery_status_does_not_start_codex',
            'agent_control_plane_task_lease_recovery_status_does_not_advance_pointer',
            'agent_control_plane_task_lease_recovery_status_does_not_dispatch_work',
            'agent_control_plane_task_lease_recovery_status_does_not_execute_adapter',
            'agent_control_plane_task_lease_recovery_status_does_not_enable_self_programming',
        ], $this->policy->nonExecutionGuarantees('agent_control_plane_task_lease_recovery_status'));
    }
}
