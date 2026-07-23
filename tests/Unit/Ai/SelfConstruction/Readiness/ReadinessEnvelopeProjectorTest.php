<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Readiness\ReadinessEnvelopeProjector;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessFailClosedPolicy;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use PHPUnit\Framework\TestCase;

final class ReadinessEnvelopeProjectorTest extends TestCase
{
    private ReadinessEnvelopeProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new ReadinessEnvelopeProjector;
    }

    /**
     * Golden: byte-compatible with the legacy
     * AtlasSelfConstructionReadinessService::wrapCertificationWorkbenchStatus output
     * (key order included) so the god-service wrap can delegate here verbatim.
     */
    public function test_read_only_envelope_is_byte_compatible_with_the_legacy_wrap(): void
    {
        $payload = ['status' => 'available', 'detail' => 'x'];
        $status = ['status' => 'available', 'event' => 'noop'];

        $envelope = $this->projector->projectCertificationWorkbenchStatus(
            keyPrefix: 'claim_lease_runtime',
            label: 'Claim/Lease Runtime',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: ['event' => 'noop'],
        );

        $this->assertSame([
            'schema_version' => 'atlas.self_construction_agent_control_plane_claim_lease_runtime_status.v1',
            'status' => 'available',
            'mode' => 'read_only_agent_control_plane_claim_lease_runtime_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'agent_control_plane_claim_lease_runtime_status' => $status,
            'agent_control_plane_claim_lease_runtime' => $payload,
            'agent_control_plane_claim_lease_runtime_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_control_plane_claim_lease_runtime_status_does_not_start_codex',
                'agent_control_plane_claim_lease_runtime_status_does_not_advance_pointer',
                'agent_control_plane_claim_lease_runtime_status_does_not_dispatch_work',
                'agent_control_plane_claim_lease_runtime_status_does_not_execute_adapter',
                'agent_control_plane_claim_lease_runtime_status_does_not_enable_self_programming',
            ],
            'human_summary' => 'Agent Control Plane Claim/Lease Runtime status is available.',
        ], $envelope);
    }

    public function test_mutating_envelope_tells_the_truth_about_runtime_writes(): void
    {
        $envelope = $this->projector->projectCertificationWorkbenchStatus(
            keyPrefix: 'task_auto_replenishment',
            label: 'Task Auto-Replenishment',
            payload: ['status' => 'available', 'generated_task_count' => 2],
            statusKey: 'status',
            extraStatusFields: ['generated_task_count' => 2],
            runtimeWritePerformed: true,
        );

        $this->assertSame('mutating_agent_control_plane_task_auto_replenishment_status', $envelope['mode']);
        $this->assertTrue($envelope['runtime_write_allowed']);
        $this->assertTrue($envelope['runtime_write_performed']);
        $this->assertFalse($envelope['execution_allowed']);
        $this->assertFalse($envelope['dispatch_allowed']);
        $this->assertFalse($envelope['ledger_write_allowed']);
        $this->assertSame('atlas.self_construction_agent_control_plane_task_auto_replenishment_status.v1', $envelope['schema_version']);
        $this->assertContains('agent_control_plane_task_auto_replenishment_status_does_not_start_codex', $envelope['non_execution_guarantees']);
        // The truthful keys are additive: everything before them keeps its position.
        $this->assertSame(
            ['schema_version', 'status', 'mode', 'execution_allowed', 'dispatch_allowed', 'ledger_write_allowed', 'runtime_write_allowed', 'runtime_write_performed'],
            array_slice(array_keys($envelope), 0, 8),
        );
    }

    public function test_read_only_envelope_never_carries_runtime_write_performed(): void
    {
        $envelope = $this->projector->projectCertificationWorkbenchStatus(
            keyPrefix: 'anything',
            label: 'Anything',
            payload: [],
            statusKey: 'status',
            extraStatusFields: [],
        );

        $this->assertSame('unknown', $envelope['status']);
        $this->assertArrayNotHasKey('runtime_write_performed', $envelope);
    }

    public function test_project_envelope_derives_outer_status_fail_closed(): void
    {
        $envelope = $this->projector->projectEnvelope(
            keyPrefix: 'completion_evidence',
            label: 'Completion Evidence',
            payload: ['status' => ReadinessFailClosedPolicy::STATUS_COMPLETED, 'receipt' => ''],
            requiredEvidenceKeys: ['receipt'],
        );

        $this->assertSame(ReadinessEnvelopeProjector::ENVELOPE_SCHEMA_VERSION, $envelope['schema_version']);
        $this->assertSame(ReadinessFailClosedPolicy::STATUS_BLOCKED, $envelope['status']);
        $this->assertSame(
            [['type' => ReadinessFailClosedPolicy::VIOLATION_EVIDENCE_MISSING, 'key' => 'receipt']],
            $envelope['violations'],
        );
        $this->assertFalse($envelope['runtime_write_performed']);
        $this->assertSame(
            ReadinessHash::stable(['status' => ReadinessFailClosedPolicy::STATUS_COMPLETED, 'receipt' => '']),
            $envelope['completion_evidence_hash'],
        );
        $this->assertSame('Completion Evidence status is blocked.', $envelope['human_summary']);
    }

    public function test_project_envelope_passes_a_clean_payload_through(): void
    {
        $payload = ['status' => ReadinessFailClosedPolicy::STATUS_COMPLETED, 'receipt' => 'sha'];

        $envelope = $this->projector->projectEnvelope(
            keyPrefix: 'completion_evidence',
            label: 'Completion Evidence',
            payload: $payload,
            requiredEvidenceKeys: ['receipt'],
            runtimeWritePerformed: true,
        );

        $this->assertSame(ReadinessFailClosedPolicy::STATUS_COMPLETED, $envelope['status']);
        $this->assertSame([], $envelope['violations']);
        $this->assertTrue($envelope['runtime_write_performed']);
        $this->assertSame($payload, $envelope['completion_evidence']);
    }
}
