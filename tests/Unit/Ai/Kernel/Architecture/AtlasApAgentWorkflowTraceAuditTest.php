<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowTraceAudit;
use Tests\TestCase;

final class AtlasApAgentWorkflowTraceAuditTest extends TestCase
{
    public function test_trace_audit_accepts_declared_terminal_workflow_trace(): void
    {
        $payload = app(AtlasApAgentWorkflowTraceAudit::class)->audit([
            'AP-200',
            'AP-202',
            'AP-205',
            'AP-203',
        ]);

        $this->assertSame('atlas.ap_agent_workflow_trace_audit.v1', $payload['schema_version']);
        $this->assertSame('valid_trace', $payload['status']);
        $this->assertSame('read_only_trace_audit', $payload['mode']);
        $this->assertSame('ap_agent_workflow_trace_audit_only_no_execution', $payload['authority']);
        $this->assertSame(4, $payload['step_count']);
        $this->assertSame(3, $payload['transition_count']);
        $this->assertTrue($payload['terminal_reached']);
        $this->assertSame(0, $payload['shape_error_count']);
        $this->assertSame(0, $payload['blocked_transition_count']);
        $this->assertSame('accept_agent_workflow_trace_as_following_declared_policy', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.repairs_trace'));
    }

    public function test_trace_audit_blocks_skipped_validation_step(): void
    {
        $payload = app(AtlasApAgentWorkflowTraceAudit::class)->audit([
            'AP-200',
            'AP-202',
            'AP-203',
        ]);

        $this->assertSame('invalid_trace', $payload['status']);
        $this->assertSame(1, $payload['blocked_transition_count']);
        $this->assertSame('AP-202', data_get($payload, 'blocked_transitions.0.from_step'));
        $this->assertSame('AP-203', data_get($payload, 'blocked_transitions.0.to_step'));
        $this->assertSame(
            'transition_not_allowed_by_workflow_registry',
            data_get($payload, 'blocked_transitions.0.errors.0.reason'),
        );
        $this->assertSame('repair_agent_workflow_trace_before_claiming_ordered_execution', $payload['next_action']);
    }

    public function test_trace_audit_blocks_terminal_exit_unknown_and_shape_errors(): void
    {
        $terminal = app(AtlasApAgentWorkflowTraceAudit::class)->audit(['AP-205', 'AP-203', 'AP-200']);
        $unknown = app(AtlasApAgentWorkflowTraceAudit::class)->audit(['AP-200', 'AP-999']);
        $shape = app(AtlasApAgentWorkflowTraceAudit::class)->audit(['AP-200', '']);

        $this->assertSame('invalid_trace', $terminal['status']);
        $this->assertSame('from_step_is_terminal', data_get($terminal, 'blocked_transitions.0.errors.0.reason'));
        $this->assertSame('invalid_trace', $unknown['status']);
        $this->assertSame('unknown_to_step', data_get($unknown, 'blocked_transitions.0.errors.0.reason'));
        $this->assertSame('invalid_trace', $shape['status']);
        $this->assertSame(1, $shape['shape_error_count']);
        $this->assertSame('trace_step_must_be_non_empty_string', data_get($shape, 'shape_errors.0.reason'));
    }
}
