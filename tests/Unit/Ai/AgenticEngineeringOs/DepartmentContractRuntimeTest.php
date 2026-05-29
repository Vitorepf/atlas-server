<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\ArchitectAgentSpecPackGateContract;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use Tests\TestCase;

final class DepartmentContractRuntimeTest extends TestCase
{
    private DepartmentContractRuntime $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DepartmentContractRuntime;
    }

    public function test_catalogue_lists_canonical_departments(): void
    {
        $c = $this->svc->catalogue();
        $this->assertSame('atlas.aaeos.department.v1', $c['schema_version']);
        $this->assertGreaterThanOrEqual(12, $c['department_count']);
        $this->assertArrayHasKey('executive_intake', $c['departments']);
        $this->assertArrayHasKey('memory', $c['departments']);
    }

    public function test_every_department_declares_gates_and_evidence_schema(): void
    {
        foreach (DepartmentContractRuntime::CATALOGUE as $dept => $info) {
            $this->assertNotEmpty($info['gates'], "department '{$dept}' must declare gates");
            $this->assertIsString($info['evidence_schema']);
            $this->assertNotSame('', $info['evidence_schema'], "department '{$dept}' must declare evidence_schema");
        }
    }

    public function test_executive_intake_has_no_upstream(): void
    {
        $this->assertSame([], DepartmentContractRuntime::CATALOGUE['executive_intake']['accepts_handoff_from']);
    }

    public function test_memory_has_no_downstream(): void
    {
        $this->assertSame([], DepartmentContractRuntime::CATALOGUE['memory']['emits_handoff_to']);
    }

    public function test_valid_handoff_accepted(): void
    {
        $r = $this->svc->validateHandoff('dev', 'review');
        $this->assertTrue($r['accepted']);
    }

    public function test_invalid_handoff_blocked(): void
    {
        $r = $this->svc->validateHandoff('dev', 'executive_intake');
        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('does not emit handoff', $r['reason']);
    }

    public function test_unknown_department_blocked(): void
    {
        $r = $this->svc->validateHandoff('wibble', 'memory');
        $this->assertFalse($r['accepted']);
        $this->assertStringContainsString('unknown department', $r['reason']);
    }

    public function test_gates_for_returns_canonical_list(): void
    {
        $gates = $this->svc->gatesFor('dev');
        $this->assertContains('plan_approved', $gates);
        $this->assertContains('tests_focused', $gates);
    }

    public function test_evidence_schema_returns_canonical(): void
    {
        $this->assertSame('atlas.dev.plan_visible.v1', $this->svc->evidenceSchemaFor('dev'));
    }

    public function test_every_department_declares_12_canon_fields(): void
    {
        $missing = $this->svc->missingFieldsByDepartment();
        $this->assertSame([], $missing, 'every department must declare the 12 canon fields');
        $this->assertTrue($this->svc->schemaFields12Present());
    }

    public function test_canon_fields_constant_has_12_entries(): void
    {
        $this->assertCount(12, DepartmentContractRuntime::CANONICAL_FIELDS);
    }

    public function test_architect_department_gates_match_spec_pack_gate_contract(): void
    {
        $this->assertSame(
            ArchitectAgentSpecPackGateContract::GATES,
            DepartmentContractRuntime::CATALOGUE['architecture']['gates'],
        );
    }

    public function test_architect_agent_spec_pack_gate_empty_input_returns_default_contract(): void
    {
        $result = $this->svc->architectAgentSpecPackGate([]);

        $this->assertSame(
            ArchitectAgentSpecPackGateContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(ArchitectAgentSpecPackGateContract::SCHEMA, $result['schema_version']);
        $this->assertSame('architecture', $result['department_id']);
        $this->assertSame([
            'risk_scope' => 'R4',
            'spec_pack_hash' => '',
            'acceptance_criteria_present' => false,
            'rollback_plan_present' => false,
            'breaking_change_matrix_present' => false,
            'operator_signature_present' => false,
        ], $result['inputs']);
    }
}
